package web

import (
	"encoding/json"
	"strings"

	"daili-go/internal/fetchers"
	"daili-go/internal/logger"
	"daili-go/internal/securestore"
	"daili-go/internal/store"
)

// ResubmitProcessingOrders 启动时重放：已支付且 process_status='processing' 的订单，
// 若上次进程中断/重启导致部分课程没来得及下到天河（orders.upstream 缺 yid），
// 补下缺失的课程。
//
// 只碰 processing（新建订单在扣积分后即标此状态的"下单处理中"阶段）；
// pending 本就留人工不自动碰；epay 历史单 process_status 默认 pending 也不碰。
// 幂等：upstream 已有成功 yid 的课程不会重复下单。
func ResubmitProcessingOrders() {
	go func() {
		defer func() { recover() }()
		rows, err := store.DB.Query(
			`SELECT order_id, platform, username, password, school, remark, courses, COALESCE(upstream, '')
			 FROM orders WHERE status='paid' AND process_status='processing'`)
		if err != nil {
			logger.Err(err, "resubmit scan failed")
			return
		}
		defer rows.Close()
		for rows.Next() {
			var orderID, platform, username, password, school, remark, coursesJSON, upstreamJSON string
			if err := rows.Scan(&orderID, &platform, &username, &password, &school,
				&remark, &coursesJSON, &upstreamJSON); err != nil {
				continue
			}
			if password, err = securestore.Decrypt(password); err != nil {
				logger.Warnf("resubmit decrypt failed: order=%s", orderID)
				continue
			}
			resubmitOrderUpstream(orderID, platform, school, username, password, remark, coursesJSON, upstreamJSON)
		}
	}()
}

// resubmitOrderUpstream 把 orders.courses 里 upstream 尚无成功 yid 的课程补下到天河。
// remark 透传用户下单备注（与首次下单口径一致）。
func resubmitOrderUpstream(orderID, platform, school, username, password, remark, coursesJSON, upstreamJSON string) {
	var courses []orderProgressCourse // 下单时 marshalCourses 存的 title/kcid 字段
	if err := json.Unmarshal([]byte(coursesJSON), &courses); err != nil {
		logger.Warnf("resubmit parse courses failed: order=%s", orderID)
		return
	}
	var upstream []skyriverUpstreamItem
	_ = json.Unmarshal([]byte(upstreamJSON), &upstream)

	done := map[string]bool{}
	for _, it := range upstream {
		if it.Yid != "" {
			done[it.Kcid] = true
		}
	}
	if school == "" {
		school = platform
	}

	added := 0
	for _, c := range courses {
		if c.Kcid == "" || done[c.Kcid] {
			continue // 已下单成功 / 无 kcid 无法定位上游课程，跳过
		}
		yid, err := fetchers.SkyriverAddOrder(platform, school, username, password, c.Title, c.Kcid, strings.TrimSpace(remark))
		if err != nil {
			logger.Warnf("resubmit add failed: order=%s kcid=%s err=%v", orderID, c.Kcid, err)
			continue
		}
		upstream = append(upstream, skyriverUpstreamItem{Title: c.Title, Kcid: c.Kcid, Yid: yid})
		done[c.Kcid] = true
		added++
	}
	if added == 0 {
		return
	}
	raw, _ := jsonMarshal(upstream)
	if _, err := store.DB.Exec(`UPDATE orders SET upstream=? WHERE order_id=?`, string(raw), orderID); err != nil {
		logger.Err(err, "resubmit update failed", "order_id", orderID)
		return
	}
	logger.Infof("resubmit done: order=%s added=%d course(s)", orderID, added)
}
