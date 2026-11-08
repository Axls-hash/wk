package sxdk

import (
	"testing"
	"time"
)

// CalcDays 复刻 cop upstream timeCalcTrueday，用固定时刻验证口径。
func TestCalcDays(t *testing.T) {
	mon := time.Date(2026, 9, 14, 0, 0, 0, 0, time.UTC)  // 周一
	wed := time.Date(2026, 9, 16, 12, 0, 0, 0, time.UTC) // 周三

	cases := []struct {
		name string
		now  time.Time
		end  string
		week string
		want int
	}{
		{"同一周(周一→周日 周一到五)", mon, "2026-09-20", "1,2,3,4,5", 5},
		{"同一周(周一→周五 周一到五)", mon, "2026-09-18", "1,2,3,4,5", 5},
		{"同一周仅周一", mon, "2026-09-14", "1", 1},
		{"跨周(周三→下周三 周一到五)", wed, "2026-09-23", "1,2,3,4,5", 6},
		{"跨两周(周一→下下周一 仅周日)", mon, "2026-09-28", "7", 2}, // 周日 9-20、9-27（结束日周一不算）
		{"结束日期是过去", wed, "2026-09-10", "1,2,3,4,5", 0},
		{"空周期", wed, "2026-09-30", "", 0},
	}
	for _, c := range cases {
		got := CalcDays(c.now, c.end, c.week)
		if got != c.want {
			t.Errorf("%s: CalcDays(%s,%s)=%d, want %d", c.name, c.end, c.week, got, c.want)
		}
	}
}

func TestBei(t *testing.T) {
	// 与 cop up_xybBei 一致：仅 xyb + runType=3 为 5
	if Bei("xyb", 3) != 5 {
		t.Fatal("xyb runType=3 倍率应为 5")
	}
	if Bei("xyb", 1) != 1 || Bei("xyb", 2) != 1 || Bei("gxy", 3) != 1 || Bei("xxt", 3) != 1 {
		t.Fatal("其余情况倍率应为 1")
	}
}

func TestValidPlatform(t *testing.T) {
	if !ValidPlatform("zxjy") || !ValidPlatform("xyb") || !ValidPlatform("bx") {
		t.Fatal("合法平台应通过校验")
	}
	if ValidPlatform("nope") {
		t.Fatal("非法平台不应通过")
	}
}

func TestDayOfWeekConversion(t *testing.T) {
	// 2026-09-14 是周一
	d := isoWeekday(time.Date(2026, 9, 14, 0, 0, 0, 0, time.UTC))
	if d != 1 {
		t.Fatalf("2026-09-14 应为周一(iso=1), got %d", d)
	}
	// 2026-09-20 是周日
	d = isoWeekday(time.Date(2026, 9, 20, 0, 0, 0, 0, time.UTC))
	if d != 7 {
		t.Fatalf("2026-09-20 应为周日(iso=7), got %d", d)
	}
}
