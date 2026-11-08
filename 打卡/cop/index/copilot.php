<?php
$title = 'copilot';
require_once('head.php');
$addsalt = md5(mt_rand(0, 999) . time());
$_SESSION['addsalt'] = $addsalt;

?>
<!-- 引入样式 -->

<link rel="stylesheet" href="../sxdk/element/index.css">
<!--<script src="https://cdn.bootcdn.net/ajax/libs/jquery/1.9.1/jquery.js"></script>-->
<!--<script src="https://cdn.bootcdn.net/ajax/libs/vue/2.6.1/vue.js"></script>-->
<!--<script src="https://cdn.staticfile.org/vue-resource/1.5.1/vue-resource.min.js"></script>-->
<script src="../sxdk/jquery/jquery.js"></script>
<script src="../sxdk/vue/vue.js"></script>
<script src="../sxdk/vue/vue-resource.min.js"></script>
<script src="../sxdk/element/index.js"></script>
<script src="../sxdk/temp/xxySchoolBase.js"></script>
<script src="../sxdk/temp/hzjSchoolBase.js"></script>



<link rel="stylesheet" href="//at.alicdn.com/t/c/font_3807157_95r8c8ifkoo.css" />
<div id="content" class="lyear-layout-content" role="main" style="padding-left: 5px; padding-top: 20px">
	<div class="app-content-body">
		<div class="wrapper-md control" id="app">
			<!-- 下单弹窗-->
			<el-dialog class="addeditDialog" :class="{ 'addeditDialog--mobile': isMobile }" :title="edit?'修改订单':'添加订单'" :visible.sync="dialog"
				:before-close="handleClose" :width="addeditDialogWidth" :height="addeditDialogHeight" :fullscreen="isMobile" align-center>
				<el-form :model="form" :rules="addFormRules" ref="Form" label-width="auto" :size="allSize"
					:label-position="isMobile ? 'top' : 'left'">
					<el-form-item label="平台" prop="platform">
						<el-select v-model="form.platform" placeholder="请选择" size="mini" :disabled="edit">
							<el-option v-for="item in platFormList" :key="item.value" :label="item.label"
								@click.native="get_price(item.value)" :value="item.value">
							</el-option>
						</el-select>
						<span style="color:#409eff;margin-left:10px;"> (单价：{{price}}/天)</span>
					</el-form-item>
					<!-- 学习通 -->
					<el-form-item label="登录方式" v-if="form.platform=='xxt'" prop="loginType">
						<el-switch v-model="userSchool" active-text="手机号" inactive-text="学号" active-color="#13ce66"
							inactive-color="#13ce66" @change="xxtLoginTypeChange">
						</el-switch>
					</el-form-item>
					<!-- 校友帮 -->
					<el-form-item label="运行方式" v-if="form.platform=='xyb'" prop="runType">
						<el-radio-group v-model="form.runType">
                            <el-radio :label="1">抓包</el-radio>
                            <el-radio :label="2">账密1号</el-radio>
                            <el-radio :label="3">绑微1号</el-radio>
                        </el-radio-group>
					</el-form-item>
					<!-- 学习通 -->
					<el-form-item v-if="form.platform=='xxt'&&!userSchool" label="学校" prop="schoolId">
						<el-select v-model="form.schoolId" placeholder="请选择学校" size="mini"
							@change="(value)=>xxtschoolChange(value)" filterable remote
							:remote-method='xxtGetSchoolList' :loading="xxtgetSchoolLoading">
							<el-option v-for="item in xxtSchoolList" :key="item.value" :label="item.text"
								:value="item.value">
							</el-option>
						</el-select>
					</el-form-item>
					<!-- 习讯云&宁夏 -->
					<el-form-item v-if="form.platform=='xxy'" label="学校" prop="schoolId">
						<el-select v-model="form.schoolId" placeholder="请选择学校" size="mini"
							@change="(value)=>xxyschoolChange(value)" filterable :loading="xxySchoolLoading">
							<el-option v-for="item in xxySchoolList" :key="item.value" :label="item.text"
								:value="item.value">
							</el-option>
						</el-select>
					</el-form-item>
					<!-- 慧职教&锦医道 -->
					<el-form-item v-if="form.platform=='hzj'" label="学校" prop="schoolId">
						<el-select v-model="form.schoolId" placeholder="请选择学校" size="mini"
							@change="(value)=>hzjschoolChange(value)" filterable :loading="hzjSchoolLoading">
							<el-option v-for="item in hzjSchoolList" :key="item.value" :label="item.text"
								:value="item.value">
							</el-option>
						</el-select>
					</el-form-item>

					<el-form-item label="账号" prop="phone">
						<el-input v-model="form.phone" size="mini" :disabled="edit"></el-input>
					</el-form-item>
					<el-form-item label="密码" prop="password">
						<el-input v-model="form.password" size="mini"></el-input>
					</el-form-item>

					<el-form-item 
					    label="项目关键词" 
						prop="projectName" 
						v-if="['xyb', 'gxy', 'xxt', 'gxzy', 'jxzhjy','bx','cxy','bx'].includes(form.platform)"
					>
						<el-input v-model="form.projectName" size="mini"></el-input>
					</el-form-item>

					<el-form-item prop="autoInfo">
						<el-button @click="getPhoneInfo" size="mini" type="primary"
							:loading="getPhoneInfoLoading">获取打卡信息</el-button>
						<span style="color:#409eff;margin-left:10px;"> 别看表单多，输入账号密码点此按钮试试。</span>
					</el-form-item>

					<el-form-item label="姓名" prop="name">
						<el-input v-model="form.name" size="mini"></el-input>
					</el-form-item>

					<!-- 岗位名称：因开发版本问题，岗位名称仅职校家园使用字段不同 -->
					<el-form-item label="岗位名称" prop="gwName" v-if="form.platform!='zxjy'">
						<el-input v-model="form.gwName" size="mini"></el-input>
					</el-form-item>
					<el-form-item label="岗位名称" prop="customizedGwName" v-if="form.platform=='zxjy'">
						<el-input v-model="form.customizedGwName" size="mini"></el-input>
					</el-form-item>


					<!-- 校友帮/工学云 -->
					<el-form-item label="国家" prop="country" v-if="['xyb', 'gxy'].includes(form.platform)">
						<el-input v-model="form.country" size="mini"></el-input>
					</el-form-item>
					<!-- 校友帮/工学云/习讯云&宁夏 -->
					<el-form-item label="省" prop="province"
					v-if="['xyb', 'gxy', 'xxy','bx'].includes(form.platform)">
						<el-input v-model="form.province" size="mini"></el-input>
					</el-form-item>
					<!-- 校友帮/工学云/习讯云&宁夏 -->
					<el-form-item label="市" prop="city"
					v-if="['xyb', 'gxy', 'xxy','bx'].includes(form.platform)">
						<el-input v-model="form.city" size="mini"></el-input>
					</el-form-item>
					<!-- 工学云 -->
					<el-form-item label="区/县" prop="area" v-if="['gxy','bx'].includes(form.platform)">
						<el-input v-model="form.area" size="mini"></el-input>
					</el-form-item>
					<!-- 校友帮 -->
					<el-form-item label="地区编码" prop="adcode" v-if="form.platform=='xyb'">
						<el-input v-model="form.adcode" size="mini"></el-input>
					</el-form-item>


					<!-- 公司地址：因开发版本问题，默认使用addressOld，仅黔职通使用officialAddress，工学云使用jobAddress -->
					<el-form-item 
					    label="公司地址" 
						prop="addressOld"
						v-if="['zxjy', 'xyb', 'xxy', 'xxt', 'hzj', 'gxzy', 'jxzhjy','bx','cxy'].includes(form.platform)"
					>
						<el-input v-model="form.addressOld" size="mini"></el-input>
					</el-form-item>
					<!-- 黔直通 -->
					<el-form-item label="公司地址" prop="officialAddress" v-if="form.platform=='qzt'">
						<el-input v-model="form.officialAddress" size="mini"></el-input>
					</el-form-item>
					<!-- 工学云 -->
					<el-form-item label="公司地址" prop="jobAddress" v-if="form.platform=='gxy'">
						<el-input v-model="form.jobAddress" size="mini"></el-input>
					</el-form-item>


					<el-form-item label="打卡地址" prop="address" v-if="form.platform!='xxy'">
						<el-input v-model="form.address" size="mini"></el-input>
					</el-form-item>

					<!-- 习讯云&宁夏 -->
					<el-form-item label="打卡地址" prop="address" v-if="form.platform=='xxy'">
						<el-select v-model="form.address" placeholder="请选择打卡地点" size="mini" filterable
							:remote-method="xxyAddressSearchPoi" :remote="xxyRemote.status" :loading="xxyRemote.loading"
							@change="(value)=>xxyCheckAddressChange(value)">
							<el-option v-for="item in xxyAddressPois" :key="item.textValue" :label="item.textValue"
								:value="item.value">
							</el-option>
						</el-select>
						<br>
						<el-switch v-model="xxyRemote.status" :width="60" inline-prompt active-text="在线搜索"
							inactive-text="离线搜索" />
					</el-form-item>


					<el-form-item label="经度" prop="lng">
						<el-input v-model="form.lng" size="mini"></el-input>
					</el-form-item>
					<el-form-item label="纬度" prop="lat">
						<el-input v-model="form.lat" size="mini"></el-input>
					</el-form-item>
					<!-- 因开发版本问题，打卡时间仅职校家园使用字段不同 -->
					<el-form-item label="打卡时间" prop="check_time" v-if="form.platform=='zxjy'">
						<el-time-picker placeholder="选择时间" v-model="form.check_time" value-format="HH:mm:ss"
							size="mini"></el-time-picker>
					</el-form-item>

					<el-form-item label="上班打卡时间" prop="up_check_time" v-if="form.platform!='zxjy'">
						<el-time-picker placeholder="选择时间" v-model="form.up_check_time" value-format="HH:mm:ss"
							size="mini"></el-time-picker>
					</el-form-item>
					<!-- 习讯云&宁夏 -->
					<el-form-item label="上班打卡类型" prop="up_remark" v-if="form.platform=='xxy'">
						<el-select v-model="form.up_remark" placeholder="请选择上班打卡类型" size="mini">
							<el-option v-for="item in mark_list" :key="item.key" :label="item.value" :value="item.key">
							</el-option>
						</el-select>
					</el-form-item>
					<!-- 工学云打卡类型 -->
					<el-form-item label="上班打卡类型" prop="up_remark" v-if="form.platform=='gxy'">
						<el-select v-model="form.up_remark" placeholder="请选择上班打卡类型" size="mini">
							<el-option v-for="item in gxy_mark_list" :key="item.key" :label="item.value" :value="item.key">
							</el-option>
						</el-select>
					</el-form-item>
					
					<!-- 下班打卡开关 -->
					<el-form-item 
						label="下班打卡" 
						prop="down_check"
						v-if="form.platform!='zxjy'"
					>
						<el-switch v-model="formOther.down_check" @change="downCheckChange" size="mini"
							active-color="#13ce66" inactive-color="#DCDFE6">
						</el-switch>
					</el-form-item>

					<el-form-item label="下班打卡时间" prop="down_check_time"
						v-if="form.platform!='zxjy'&&formOther.down_check">
						<el-time-picker placeholder="选择时间" v-model="form.down_check_time" value-format="HH:mm:ss"
							size="mini"></el-time-picker>
					</el-form-item>

					<!-- 习讯云&宁夏 -->
					<el-form-item label="下班打卡类型" prop="down_remark" v-if="form.platform=='xxy'&&formOther.down_check">
						<el-select v-model="form.down_remark" placeholder="请选择下班打卡类型" size="mini">
							<el-option v-for="item in mark_list" :key="item.key" :label="item.value" :value="item.key">
							</el-option>
						</el-select>
					</el-form-item>
					<!-- 工学云打卡类型 -->
					<el-form-item label="下班打卡类型" prop="down_remark" v-if="form.platform=='gxy'&&formOther.down_check">
						<el-select v-model="form.down_remark" placeholder="请选择下班打卡类型" size="mini">
							<el-option v-for="item in gxy_mark_list" :key="item.key" :label="item.value" :value="item.key">
							</el-option>
						</el-select>
					</el-form-item>
					<el-form-item label="打卡周期" prop="check_week">
						<el-checkbox-group v-model="form.check_week" size="mini">
							<el-checkbox label="0">周一</el-checkbox>
							<el-checkbox label="1">周二</el-checkbox>
							<el-checkbox label="2">周三</el-checkbox>
							<el-checkbox label="3">周四</el-checkbox>
							<el-checkbox label="4">周五</el-checkbox>
							<el-checkbox label="5">周六</el-checkbox>
							<el-checkbox label="6">周日</el-checkbox>
						</el-checkbox-group>
					</el-form-item>
					<el-form-item label="结束时间" prop="end_time">
						<el-date-picker type="date" placeholder="选择日期" v-model="form.end_time" value-format="yyyy-MM-dd"
							size="mini"></el-date-picker>
						<span style="color:#409eff;margin-left:10px;"> 日期当天是最后一天打卡</span>
					</el-form-item>
					<!-- 工学云：项目开始时间 / 今天是第几周 -->
					<el-form-item label="项目开始时间" prop="projectStartTime" v-if="form.platform=='gxy'">
						<el-date-picker type="date" placeholder="选择项目开始时间" v-model="form.projectStartTime"
							value-format="yyyy-MM-dd" size="mini" @change="onGxyProjectStartChange"></el-date-picker>
					</el-form-item>
					<el-form-item label="今天是第几周？" prop="nowWeekNum" v-if="form.platform=='gxy'">
						<el-input-number v-model="form.nowWeekNum" :step="1" step-strictly size="mini" :min="0"
							:precision="0"></el-input-number>
					</el-form-item>
					<el-divider></el-divider>
					<el-form-item label="日报" prop="day_paper">
						<el-switch v-model="form.day_paper" size="mini" active-color="#13ce66" inactive-color="#DCDFE6">
						</el-switch>
					</el-form-item>
					<el-form-item label="日报最小字数" prop="day_paper_minSize" v-if="form.day_paper">
						<el-input-number v-model="form.paperNumSetting.day.minSize" :step="100" step-strictly size="mini" 
							:min="0"></el-input-number>
						<span style="color:#409eff;margin-left:10px;"> 0表示不限制</span>
					</el-form-item>
					<el-form-item label="日报最大字数" prop="day_paper_maxSize" v-if="form.day_paper">
					    <el-input-number v-model="form.paperNumSetting.day.maxSize" :step="100" step-strictly size="mini" 
							:min="0"></el-input-number>
						<span style="color:#409eff;margin-left:10px;"> 0表示不限制</span>
					</el-form-item>
					<el-divider></el-divider>
					<el-form-item label="周报" prop="week_paper">
						<el-switch v-model="form.week_paper" size="mini" active-color="#13ce66"
							inactive-color="#DCDFE6">
						</el-switch>
					</el-form-item>
					<!-- 职校家园/黔直通 -->
					<el-form-item label="周报提交时间" prop="weekPaperSubmitWeek" v-if="form.week_paper">
						<el-input-number v-model="form.weekPaperSubmitWeek" :step="1" step-strictly size="mini" :max="7"
							:min="1"></el-input-number>
						<span style="color:#409eff;margin-left:10px;"> 周一到周七</span>
					</el-form-item>
					<el-form-item label="周报最小字数" prop="week_paper_minSize" v-if="form.week_paper">
						<el-input-number v-model="form.paperNumSetting.week.minSize" :step="100" step-strictly size="mini" 
							:min="0"></el-input-number>
						<span style="color:#409eff;margin-left:10px;"> 0表示不限制</span>
					</el-form-item>
					<el-form-item label="周报最大字数" prop="week_paper_maxSize" v-if="form.week_paper">
					    <el-input-number v-model="form.paperNumSetting.week.maxSize" :step="100" step-strictly size="mini" 
							:min="0"></el-input-number>
						<span style="color:#409eff;margin-left:10px;"> 0表示不限制</span>
					</el-form-item>
					<el-divider></el-divider>
					<el-form-item label="月报" prop="month_paper">
						<el-switch v-model="form.month_paper" size="mini" active-color="#13ce66"
							inactive-color="#DCDFE6">
						</el-switch>
					</el-form-item>
					<!-- 职校家园/黔直通 -->
					<el-form-item label="月报提交时间" prop="monthPaperSubmitMonth" v-if="form.month_paper">
						<el-input-number v-model="form.monthPaperSubmitMonth" :step="1" step-strictly size="mini"
							:max="28" :min="0"></el-input-number>
						<span style="color:#409eff;margin-left:10px;"> 0 代表为月底最后一天提交</span>
					</el-form-item>
					<el-form-item label="月报最小字数" prop="month_paper_minSize" v-if="form.month_paper">
						<el-input-number v-model="form.paperNumSetting.month.minSize" :step="100" step-strictly size="mini" 
							:min="0"></el-input-number>
						<span style="color:#409eff;margin-left:10px;"> 0表示不限制</span>
					</el-form-item>
					<el-form-item label="月报最大字数" prop="month_paper_maxSize" v-if="form.month_paper">
					    <el-input-number v-model="form.paperNumSetting.month.maxSize" :step="100" step-strictly size="mini" 
							:min="0"></el-input-number>
						<span style="color:#409eff;margin-left:10px;"> 0表示不限制</span>
					</el-form-item>

					<el-divider></el-divider>
					<p v-if="form.platform=='gxy'" style="color:red;padding-left:50px;font-size:12px">总结仅支持在补卡/报告中手动提交执行</p>
					<el-form-item label="总结最小字数" prop="summary_paper_minSize" v-if="form.platform=='gxy'">
						<el-input-number v-model="form.paperNumSetting.summary.minSize" :step="100" step-strictly size="mini" 
							:min="0"></el-input-number>
						<span style="color:#409eff;margin-left:10px;"> 0表示不限制</span>
					</el-form-item>
					<el-form-item label="总结最大字数" prop="summary_paper_maxSize" v-if="form.platform=='gxy'">
					    <el-input-number v-model="form.paperNumSetting.summary.maxSize" :step="100" step-strictly size="mini" 
							:min="0"></el-input-number>
						<span style="color:#409eff;margin-left:10px;"> 0表示不限制</span>
					</el-form-item>
					<el-divider v-if="form.platform=='gxy'"></el-divider>


					<el-form-item label="遵循法定节假日" prop="holiday">
						<el-switch v-model="form.holiday" size="mini" active-color="#13ce66" inactive-color="#DCDFE6">
						</el-switch>
					</el-form-item>
					<el-form-item label="预计扣费" prop="yuji">
						<el-input v-model="yuji" readonly size="mini" v-if="!edit"></el-input>
						<el-input v-model="editYuji" readonly size="mini" v-else></el-input>
					</el-form-item>
				</el-form>
				<div slot="footer" class="dialog-footer mobile-dialog-footer">
					<el-button @click="dialog = false" :size="allSize">取 消</el-button>
					<el-button type="primary" @click="add" :loading="addloading" :size="allSize">{{edit?'修改':'添加'}}</el-button>
				</div>
			</el-dialog>
			<el-dialog class="addeditDialog" :class="{ 'addeditDialog--mobile': isMobile }" :title="buPapersForm.phone+'补卡/报告'" :visible.sync="bDialog"
				:before-close="BhandleClose" :width="addeditDialogWidth" :height="addeditDialogHeight" :fullscreen="isMobile" align-center>
				<el-form :model="buPapersForm" :rules="buPapersFormRules" ref="BForm" label-width="auto" :size="allSize"
					:label-position="isMobile ? 'top' : 'left'">

					<el-form-item label="类型" prop="levelName">
                      <el-radio-group v-model="buPapersForm.levelName" :size="allSize" class="bu-papers-radio-group">
                        <el-radio label="日报" v-if="buPapersForm.platform=='qzt'?false:true">日报</el-radio>
                        <el-radio label="周报">周报</el-radio>
                        <el-radio label="月报">月报</el-radio>
                        <el-radio v-if="['gxy'].includes(buPapersForm.platform)" label="总结">总结</el-radio>
                        <el-radio v-if="['gxy','xyb'].includes(buPapersForm.platform)" label="上班打卡">上班打卡</el-radio>
                        <el-radio v-if="['gxy','xyb'].includes(buPapersForm.platform)" label="下班打卡">下班打卡</el-radio>
                        <el-radio v-if="['gxy','xyb'].includes(buPapersForm.platform)" label="上下班打卡">上下班打卡</el-radio>
                      </el-radio-group>
                     </el-form-item>

					<el-form-item label="开始时间" prop="startTime">
							<el-date-picker type="date" placeholder="选择日期" v-model="buPapersForm.startTime" value-format="yyyy-MM-dd"
							size="mini"></el-date-picker>
					</el-form-item>


					<el-form-item label="结束时间" prop="startTime">
						<el-date-picker type="date" placeholder="选择日期" v-model="buPapersForm.endTime" value-format="yyyy-MM-dd"
							size="mini"></el-date-picker>
					</el-form-item>
				</el-form>
				<div slot="footer" class="dialog-footer mobile-dialog-footer">
					<el-button @click="bDialog = false" :size="allSize">取 消</el-button>
					<el-button type="primary" @click="buPapers" :loading="buPapersloading" :size="allSize">下单</el-button>
				</div>
			</el-dialog>
			<el-dialog class="addeditDialog" :class="{ 'addeditDialog--mobile': isMobile }" :title="bindWxForm.phone+'升级绑微'" :visible.sync="bindWxDialog"
				:before-close="bindWxhandleClose" :width="addeditDialogWidth" :height="addeditDialogHeight" :fullscreen="isMobile" align-center>
				<div>
				    <p>
				        1.绑定微信将会一次性<b style="color:red;font-size:14px">扣除本平台余额{{price*1000}}</b>作为绑定升级费用，且永久不可退。</br>
                        2.绑定后每日单价将会提升至目前单价的5倍，所以需要补齐剩余天数的4倍费用，提交将会<b style="color:red;font-size:14px">扣除下方预计补充费用</b>。
				    </p>
				    <p>
				        预计补充费用<el-input v-model="bindWxMoney" readonly size="mini"></el-input>
				    </p>
				</div>
				<div slot="footer" class="dialog-footer mobile-dialog-footer">
					<el-button @click="bindWxDialog = false" :size="allSize">取 消</el-button>
					<el-button type="primary" @click="xybBindWx" :loading="bindWxLoading" :size="allSize">升级绑微</el-button>
				</div>
			</el-dialog>
			<!-- wxpush -->
			<el-dialog class="addeditDialog" :class="{ 'addeditDialog--mobile': isMobile }" :title="form.phone+'绑定微信通知'" :visible.sync="wxpushDialog"
				:before-close="wxpushhandleClose" :width="addeditDialogWidth" :height="addeditDialogHeight" :fullscreen="isMobile" align-center>
				<div v-loading="getWxPushLoading" element-loading-text="加载二维码中..." class="wxpush-content">
				    <el-image :src='formOther.wxpush_img' class="wxpush-qrcode">
				        <div slot="error" class="image-slot">
                            <i class="el-icon-picture-outline" style="font-size:64px"></i>
                        </div>
				    </el-image>
		            <p style="margin: 12px; font-size: 12px; color:red;">
            			上方二维码是wxpusher推送，但现在，我们更推荐showdoc推送，请关注showdoc微信公众号，并将公众号发送您的专属推送地址填入下方，最后点击使用showdoc按钮
            		</p>
        			<div class="wxpush-input-row">地址<el-input v-model="form.wxpush" :size="allSize" placeholder="填写您的showdoc专属推送地址"></el-input></div>
				</div>
				<div slot="footer" class="dialog-footer mobile-dialog-footer">
					<el-button @click="wxpushDialog = false" :size="allSize">取 消</el-button>
					<el-button type="primary" @click="useShowDoc" :loading="wxpushLoading" :size="allSize">使用showdoc</el-button>
				</div>
			</el-dialog>
			<!--在线反馈-->
			<el-dialog 
              class="addCommentDialog" 
              :class="{ 'addeditDialog--mobile': isMobile }"
              title="在线反馈" 
              :visible.sync="addCommentDialogVisible"
              :before-close="addCommenthandleClose"
              :width="addeditDialogWidth" :height="addeditDialogHeight"
              :fullscreen="isMobile"
              align-center>
              
              <!-- 反馈表单 -->
              <el-form :model="addCommentForm" :label-width="isMobile ? 'auto' : '100px'" :size="allSize" :label-position="isMobile ? 'top' : 'right'">
                   <el-form-item label="订单平台" prop="platform" v-if="addCommentForm.id">
                  <el-input v-model="addCommentForm.platform" disabled></el-input>
                </el-form-item>
                <el-form-item label="订单账号" prop="phone" v-if="addCommentForm.id">
                  <el-input v-model="addCommentForm.phone" disabled></el-input>
                </el-form-item>
                <el-form-item label="反馈问题" prop="question">
                  <el-input 
                    type="textarea" 
                    :rows="4" 
                    v-model="addCommentForm.question" 
                    placeholder="请详细描述您遇到的问题或建议...">
                  </el-input>
                </el-form-item>
                
               
              </el-form>
              
              <!-- 历史记录 -->
              <div class="history-section">
                <h4>历史反馈记录</h4>
                <el-table 
                  v-if="!isMobile"
                  :data="commentList" 
                  border 
                  :size="allSize" 
                  style="width: 100%"
                  v-loading="getCommentListLoading">
                  <el-table-column prop="id" label="ID" width="60"></el-table-column>
                  <el-table-column prop="platform" label="平台"></el-table-column>
                 
                  <el-table-column prop="question" label="反馈内容"></el-table-column>
                  <el-table-column prop="status" label="状态" width="100">
                    <template slot-scope="scope">
                      <el-tag :type="getStatusTagType(scope.row.status)">
                        {{ getStatusText(scope.row.status) }}
                      </el-tag>
                    </template>
                  </el-table-column>
                  <el-table-column prop="answer" label="回复" width="120">
                    <template slot-scope="scope">
                      <el-button 
                        type="text" 
                        :size="allSize" 
                        @click="showAnswer(scope.row)"
                        :disabled="scope.row.status!=3">
                        查看回复
                      </el-button>
                    </template>
                  </el-table-column>
                   <el-table-column prop="createTime" label="提交时间" width="160">
                    <template slot-scope="scope">
                      {{ scope.row.createTime }}
                    </template>
                  </el-table-column>
                </el-table>
                <div v-else class="comment-mobile-list" v-loading="getCommentListLoading">
                  <div v-for="item in (commentList || [])" :key="item.id" class="comment-mobile-item">
                    <div class="comment-mobile-head">
                      <el-tag :type="getStatusTagType(item.status)" size="mini">{{ getStatusText(item.status) }}</el-tag>
                      <span class="comment-mobile-time">{{ item.createTime }}</span>
                    </div>
                    <div class="comment-mobile-question">{{ item.question }}</div>
                    <el-button type="text" :size="allSize" @click="showAnswer(item)" :disabled="item.status!=3">查看回复</el-button>
                  </div>
                  <div v-if="!(commentList && commentList.length)" class="batch-log-empty">暂无反馈记录</div>
                </div>
              </div>
              
              <div slot="footer" class="dialog-footer mobile-dialog-footer">
                <el-button @click="addCommentDialogVisible = false" :size="allSize">取 消</el-button>
                <el-button type="primary" @click="submitaddComment" :loading="submitaddCommentLoading" :size="allSize">
                  提交反馈
                </el-button>
              </div>
            </el-dialog>
			<!-- 移动端订单操作抽屉 -->
			<el-drawer
				title="订单操作"
				:visible.sync="mobileActionDrawerVisible"
				direction="btt"
				size="60%"
				class="mobile-action-drawer"
				:with-header="true">
				<div class="mobile-action-list" v-if="mobileActionItem">
					<el-button v-if="mobileActionItem.platform=='xyb'&&mobileActionItem.runType==2" :size="allSize" @click="runMobileAction('bingWx')">升级绑微</el-button>
					<el-button type="primary" :size="allSize" @click="runMobileAction('nowCheck')">立即打卡</el-button>
					<el-button :size="allSize" @click="runMobileAction('changeCheckCode')">{{ mobileActionItem.code==1 ? '暂停' : '启动' }}</el-button>
					<el-button :size="allSize" @click="runMobileAction('editOrderInfo')">编辑</el-button>
					<el-button :size="allSize" @click="runMobileAction('log')">查看日志</el-button>
					<el-button :size="allSize" @click="runMobileAction('copyPicUrl')">专属图库</el-button>
					<el-button v-if="['zxjy', 'qzt', 'gxy','xyb','xxy','xxt'].includes(mobileActionItem.platform)" :size="allSize" @click="runMobileAction('buPapers')">补卡/报告</el-button>
					<el-button v-if="['zxjy', 'qzt', 'gxy','xyb','xxy','xxt'].includes(mobileActionItem.platform)" :size="allSize" @click="runMobileAction('getAsyncTask')">补卡/报告记录</el-button>
					<el-button :size="allSize" @click="runMobileAction('showErWeiMa')">微信推送</el-button>
					<el-button :size="allSize" @click="runMobileAction('getCommentList')">问题反馈</el-button>
					<el-button type="danger" plain :size="allSize" @click="runMobileAction('del')">删除</el-button>
				</div>
			</el-drawer>
			<!-- 批量执行进度 -->
			<el-dialog
				class="batchDialog"
				:class="{ 'batchDialog--mobile': isMobile }"
				:title="batchRunningTitle"
				:visible.sync="batchDialogVisible"
				:width="batchDialogWidth"
				:fullscreen="isMobile"
				:close-on-click-modal="!batchRunning"
				:show-close="!batchRunning"
				:before-close="batchDialogBeforeClose">
				<div class="batch-progress-wrap">
					<div class="batch-progress-info">
						<span>进度：{{ batchCompleted }}/{{ batchTotal }}</span>
						<span>成功：<em class="batch-stat-success">{{ batchSuccessCount }}</em></span>
						<span>失败：<em class="batch-stat-fail">{{ batchFailCount }}</em></span>
					</div>
					<el-progress
						:percentage="batchProgress"
						:stroke-width="isMobile ? 14 : 6"
						:status="batchRunning ? null : (batchFailCount > 0 ? 'warning' : 'success')">
					</el-progress>
				</div>
				<div class="batch-log-title">执行日志</div>
				<el-table
					v-if="!isMobile"
					ref="batchLogTable"
					:data="batchLogs"
					border
					size="mini"
					:max-height="batchLogMaxHeight"
					style="width:100%"
					empty-text="等待执行...">
					<el-table-column prop="index" label="序号" width="50"></el-table-column>
					<el-table-column prop="phone" label="账号" width="120"></el-table-column>
					<el-table-column prop="name" label="姓名" width="80"></el-table-column>
					<el-table-column prop="platform" label="平台" width="100"></el-table-column>
					<el-table-column prop="status" label="状态" width="70">
						<template slot-scope="scope">
							<el-tag :type="scope.row.status === '成功' ? 'success' : 'danger'" size="mini">
								{{ scope.row.status }}
							</el-tag>
						</template>
					</el-table-column>
					<el-table-column prop="msg" label="运行结果" show-overflow-tooltip min-width="160"></el-table-column>
					<el-table-column prop="time" label="时间" width="90"></el-table-column>
				</el-table>
				<div
					v-else
					ref="batchLogMobileWrap"
					class="batch-log-mobile"
					:style="{ maxHeight: batchLogMaxHeight + 'px' }">
					<div
						v-for="log in batchLogs"
						:key="log.index"
						class="batch-log-mobile-item"
						:class="log.status === '成功' ? 'is-success' : 'is-fail'">
						<div class="batch-log-mobile-head">
							<span class="batch-log-mobile-index">#{{ log.index }}</span>
							<el-tag :type="log.status === '成功' ? 'success' : 'danger'" size="mini">{{ log.status }}</el-tag>
							<span class="batch-log-mobile-time">{{ log.time }}</span>
						</div>
						<div class="batch-log-mobile-row"><span class="label">账号</span><span class="batch-log-mobile-value">{{ log.phone }}</span></div>
						<div class="batch-log-mobile-row"><span class="label">姓名</span><span class="batch-log-mobile-value">{{ log.name }}</span></div>
						<div class="batch-log-mobile-row"><span class="label">平台</span><span class="batch-log-mobile-value">{{ log.platform }}</span></div>
						<div class="batch-log-mobile-row batch-log-mobile-msg">
							<span class="label">结果</span>
							<span class="batch-log-mobile-value batch-log-mobile-msg-text">{{ log.msg }}</span>
						</div>
					</div>
					<div v-if="batchLogs.length === 0" class="batch-log-empty">等待执行...</div>
				</div>
				<div slot="footer" class="dialog-footer batch-dialog-footer">
					<el-button v-if="!batchRunning" type="primary" @click="batchDialogVisible = false" :size="allSize">关 闭</el-button>
					<el-button v-else type="primary" :loading="true" :size="allSize" class="batch-running-btn">执行中...</el-button>
				</div>
			</el-dialog>
			<!-- 列表 -->
			<el-card class="box-card">
				<el-alert :size="allSize" title="TaiShan版本号：260901，欢迎下单，问题请反馈" type="success" :closable="true"></el-alert>
				<div class="page-toolbar" :class="{ 'page-toolbar--mobile': isMobile }">
					<div class="page-toolbar-actions">
						<?php if ($userrow['uid'] == 1) { ?>
							<el-button type="warning" :size="allSize" @click="update" :loading="updateLoading" plain>拉取进度</el-button>
						<?php } ?>
						<el-button type="primary" :size="allSize" @click="openAddDialog">提交订单</el-button>
						<el-button type="warning" :size="allSize" @click="picUpload" plain>图片上传</el-button>
						<el-button type="warning" :size="allSize" @click="handleCommand({'type':'getCommentList',item:{id:''}})" plain>问题反馈</el-button>
						<?php if ($userrow['uid'] == 1) { ?>
							<span class="page-toolbar-extra">{{userrow.msg}}</span>
						<?php } ?>
					</div>
					<div class="page-toolbar-search" :class="{ 'page-toolbar-search--mobile': isMobile }">
						<el-select v-if="isMobile" v-model="cx.search" placeholder="条件" :size="allSize" class="mobile-search-type">
							<el-option label="账号" value="phone"></el-option>
							<el-option label="密码" value="password"></el-option>
							<el-option v-if="<?php echo $userrow['uid'] == 1 ? 'true' : 'false';?>" label="UID" value="uid"></el-option>
						</el-select>
						<el-input placeholder="模糊查询" v-model="cx.qq" class="input-with-select" :size="allSize">
							<el-select v-if="!isMobile" v-model="cx.search" style="width: 80px" placeholder="条件" slot="prepend">
								<el-option label="账号" value="phone"></el-option>
								<el-option label="密码" value="password"></el-option>
								<el-option v-if="<?php echo $userrow['uid'] == 1 ? 'true' : 'false';?>"label="UID" value="uid"></el-option>
							</el-select>
							<el-button slot="append" icon="el-icon-search" @click="query">搜索</el-button>
						</el-input>
					</div>
					<div class="batch batch-bar" v-show="multipleSelection.length>0">
						<span class="batch-selected">已选择 {{ multipleSelection.length }} 条</span>
						<el-select v-model="batchType" placeholder="请选择需要批量的操作" :size="allSize" class="batch-select">
							<el-option v-for="item in batchList" :key="item.value" :label="item.label" :value="item.value">
							</el-option>
						</el-select>
						<el-button type="warning" @click="batchFuntion" :size="allSize" plain :loading="batchLoading">执行</el-button>
					</div>
				</div>
				<div class="card-body" style="padding:0px">
					<!-- 桌面端表格 -->
					<el-table v-if="!isMobile" :data="tableData" :row-class-name="tableRowClassName" v-loading="loading || actionLoading"
						:element-loading-text="actionLoading ? '处理中，请稍候...' : '载入数据中'" ref="multipleTable" :size="allSize" empty-text="啊哦！一条订单都没有哦！"
						highlight-current-row border style="font-size:12px" @selection-change="handleSelectionChange">
						<el-table-column type="selection" width="55">
						</el-table-column>
						<el-table-column type="index" label="序号">
						</el-table-column>
						<el-table-column label="操作" fixed="left" width="50">
							<template slot-scope="scope">
								<el-dropdown @command="handleCommand">
									<span class="el-dropdown-link" style="color:#409EFF">
										更多<i class="el-icon-arrow-down el-icon--right"></i>
									</span>
									<el-dropdown-menu slot="dropdown">
									    <el-dropdown-item v-if="scope.row.platform=='xyb'&&scope.row.runType==2"
											:command="{item:scope.row,type:'bingWx'}"
										>升级绑微</el-dropdown-item>
										<el-dropdown-item
											:command="{item:scope.row,type:'nowCheck'}"
										>立即打卡</el-dropdown-item>
										<el-dropdown-item :command="{item:scope.row,type:'log'}">查看日志</el-dropdown-item>
										<el-dropdown-item
											:command="{item:scope.row,type:'copyPicUrl'}"
										>专属图库</el-dropdown-item>
										<el-dropdown-item
											v-if="['zxjy', 'qzt', 'gxy','xyb','xxy','xxt'].includes(scope.row.platform)"
											:command="{item:scope.row,type:'buPapers'}"
										>补卡/报告</el-dropdown-item>
										<el-dropdown-item 
											v-if="['zxjy', 'qzt', 'gxy','xyb','xxy','xxt'].includes(scope.row.platform)" 
											:command="{item:scope.row,type:'getAsyncTask'}"
										>补卡/报告记录</el-dropdown-item>

										<el-dropdown-item
											:command="{item:scope.row,type:'changeCheckCode'}">{{scope.row.code==1?"暂停":"启动"}}</el-dropdown-item>
										<el-dropdown-item
											:command="{item:scope.row,type:'showErWeiMa'}">微信推送</el-dropdown-item>
											<el-dropdown-item :command="{item:scope.row,type:'getCommentList'}">问题反馈</el-dropdown-item>
										<el-dropdown-item
											:command="{item:scope.row,type:'editOrderInfo'}">编辑</el-dropdown-item>
										<el-dropdown-item :command="{item:scope.row,type:'del'}">删除</el-dropdown-item>
									</el-dropdown-menu>
								</el-dropdown>
							</template>
						</el-table-column>
						<el-table-column prop="uid" label="UID" v-if="<?php echo $userrow['uid'] == 1 ? 'true' : 'false'; ?>">
						</el-table-column>
						<el-table-column label="平台">
							<template slot-scope="scope">
								{{
								scope.row.platform=="zxjy"?"职校家园":
								scope.row.platform=="qzt"?"黔职通":
								scope.row.platform=="xyb"?"校友帮":
								scope.row.platform=="xxy"?"习讯云&宁夏":
								scope.row.platform=="xxt"?"学习通":
								scope.row.platform=="gxy"?"工学云":
								scope.row.platform=="hzj"?"慧职教&锦医道":
								scope.row.platform=="gxzy"?"广西职业":
								scope.row.platform=="jxzhjy"?"江西智慧教育":
								scope.row.platform=="cxy"?"成学云":
								scope.row.platform=="bx"?"博行":"未知"
								}}
								{{
								scope.row.platform=="xyb"?scope.row.runType==1?"(抓包)":scope.row.runType==2?"(账密1号)":scope.row.runType==3?"(绑微1号)":"":""
								}}
							</template>
						</el-table-column>
						<el-table-column prop="phone" label="账号" width="150">
						</el-table-column>
						<el-table-column prop="password" label="密码" width="150">
						</el-table-column>
						<el-table-column label="状态" show-overflow-tooltip>
							<template slot-scope="scope">
								<el-tag type="primary" v-if="scope.row.code==1">
									运行中
								</el-tag>
								<el-tag type="warning" v-else> 未运行 </el-tag>
							</template>
						</el-table-column>
						<el-table-column prop="name" label="姓名" width="80">
						</el-table-column>
						<el-table-column prop="address" label="打卡地址" width="250">
						</el-table-column>
						<el-table-column label="微信推送" show-overflow-tooltip>
							<template slot-scope="scope">
								<el-tag type="primary" v-if="scope.row.wxpush"> 已开启 </el-tag>
								<el-tag type="warning" v-else> 未开启 </el-tag>
							</template>
						</el-table-column>
						<el-table-column label="上班打卡时间" width="120">
							<template slot-scope="scope">
								{{scope.row.check_time||scope.row.up_check_time}}
							</template>
						</el-table-column>
						<el-table-column prop="down_check_time" label="下班打卡时间" width="120">
						</el-table-column>
						<el-table-column label="打卡周期" width="180">
							<template slot-scope="scope">
								<el-tag type="success" v-if='scope.row.check_week && scope.row.check_week.indexOf("0") > -1'>周一</el-tag>
								<el-tag type="success" v-if='scope.row.check_week && scope.row.check_week.indexOf("1") > -1'>周二</el-tag>
								<el-tag type="success" v-if='scope.row.check_week && scope.row.check_week.indexOf("2") > -1'>周三</el-tag>
								<el-tag type="success" v-if='scope.row.check_week && scope.row.check_week.indexOf("3") > -1'>周四</el-tag>
								<el-tag type="success" v-if='scope.row.check_week && scope.row.check_week.indexOf("4") > -1'>周五</el-tag>
								<el-tag type="success" v-if='scope.row.check_week && scope.row.check_week.indexOf("5") > -1'>周六</el-tag>
								<el-tag type="success" v-if='scope.row.check_week && scope.row.check_week.indexOf("6") > -1'>周日</el-tag>
							</template>
						</el-table-column>
						<el-table-column label="报告" width="180">
							<template slot-scope="scope">
								<el-tag type="success" v-if='scope.row.day_paper==1'>日报</el-tag>
								<el-tag type="success" v-if='scope.row.week_paper==1'>周报</el-tag>
								<el-tag type="success" v-if='scope.row.month_paper==1'>月报</el-tag>
							</template>
						</el-table-column>
						<el-table-column prop="end_time" label="到期时间" width="120">
						</el-table-column>
						<el-table-column prop="createTime" label="创建时间" width="150">
						</el-table-column>
					</el-table>
					<!-- 移动端卡片列表 -->
					<div v-else class="order-mobile-list" v-loading="loading || actionLoading"
						:element-loading-text="actionLoading ? '处理中，请稍候...' : '载入数据中'">
						<div v-if="!(tableData && tableData.length)" class="order-mobile-empty">啊哦！一条订单都没有哦！</div>
						<div
							v-for="(row, index) in (tableData || [])"
							:key="row.id || index"
							class="order-mobile-item"
							:class="{ 'is-running': row.code==1, 'is-selected': isMobileRowSelected(row) }">
							<div class="order-mobile-head">
								<span class="mobile-select-btn" :class="{ active: isMobileRowSelected(row) }" @click="toggleMobileSelection(row)">
									<i class="el-icon-check"></i>
								</span>
								<div class="order-mobile-title">
									<span class="order-mobile-phone">{{ row.phone }}</span>
									<el-tag :type="row.code==1 ? 'primary' : 'warning'" size="mini">{{ row.code==1 ? '运行中' : '未运行' }}</el-tag>
								</div>
								<el-button type="text" :size="allSize" @click="openMobileActions(row)">更多</el-button>
							</div>
							<div class="order-mobile-meta">
								<span>{{ getOrderPlatformText(row) }}</span>
								<span v-if="row.name"> · {{ row.name }}</span>
							</div>
							<div class="order-mobile-row">
								<span class="label">到期</span>
								<span :class="getEndTimeClass(row.end_time)">{{ row.end_time || '-' }}</span>
							</div>
							<div class="order-mobile-row">
								<span class="label">打卡</span>
								<span>{{ row.check_time || row.up_check_time || '-' }}{{ row.down_check_time ? ' / ' + row.down_check_time : '' }}</span>
							</div>
							<div class="order-mobile-row">
								<span class="label">周期</span>
								<span>{{ formatCheckWeekShort(row.check_week) }}</span>
							</div>
							<div class="order-mobile-row" v-if="row.address">
								<span class="label">地址</span>
								<span class="order-mobile-address">{{ row.address }}</span>
							</div>
							<div class="order-mobile-tags">
								<el-tag v-if="row.day_paper==1" size="mini" type="success">日报</el-tag>
								<el-tag v-if="row.week_paper==1" size="mini" type="success">周报</el-tag>
								<el-tag v-if="row.month_paper==1" size="mini" type="success">月报</el-tag>
								<el-tag size="mini" :type="row.wxpush ? 'primary' : 'info'">{{ row.wxpush ? '微信已开' : '微信未开' }}</el-tag>
							</div>
							<div class="order-mobile-actions">
								<el-button type="primary" plain :size="allSize" @click="handleCommand({item:row,type:'nowCheck'})">打卡</el-button>
								<el-button type="warning" plain :size="allSize" @click="handleCommand({item:row,type:'changeCheckCode'})">{{ row.code==1 ? '暂停' : '启动' }}</el-button>
								<el-button plain :size="allSize" @click="handleCommand({item:row,type:'editOrderInfo'})">编辑</el-button>
							</div>
						</div>
					</div>
					<el-pagination v-if="!isMobile" :size="allSize" @size-change="sizechange" @current-change="pagechange"
						:current-page.sync="currentpage" :page-sizes="[10, 20, 50, 100, 200, 500]" :page-size="pagesize"
						layout="total,sizes, prev, pager, next, jumper" :total="pagecount">
					</el-pagination>
					<el-pagination v-else :size="allSize" @size-change="sizechange" @current-change="pagechange"
						:current-page.sync="currentpage" :page-size="pagesize"
						layout="prev, pager, next" :total="pagecount" class="mobile-pagination">
					</el-pagination>
				</div>
			</el-card>
		</div>
	</div>
	<script>
		var vm = new Vue({
			el: "#app",
			data() {
				return {
					addeditDialogWidth: "675px",
					addeditDialogHeight: "600px",
					allSize: "small",
					isMobile: false,
					batchDialogWidth: "720px",
					batchLogMaxHeight: 360,
					mobileActionDrawerVisible: false,
					mobileActionItem: null,
					xxyRemote: {
						status: false,
						loading: false
					},
					loading: false,
					addloading: false,
					buPapersloading:false,
					addCommentDialogVisible:false,
					getCommentListLoading:false,
					submitaddCommentLoading:false,
					bindWxLoading:false,
					wxpushLoading:false,
					getPhoneInfoLoading: false,
					updateLoading: false,
					xxtgetSchoolLoading: false,
					batchLoading: false,
					actionLoading: false,
					getWxPushLoading: false,
					xxySchoolLoading: false,
					hzjSchoolLoading: false,
					batchDialogVisible: false,
					batchRunning: false,
					batchRunningTitle: "",
					batchLogs: [],
					batchTotal: 0,
					batchCompleted: 0,
					batchProgress: 0,
					batchSuccessCount: 0,
					batchFailCount: 0,
					currentpage: 1, //默认在第几页
					pagesize: 20, //每页显示的数量
					pagecount: 20, //总数的默认值，后面会做调整，此数值无参考意义
					price: 0,
					dialog: false,
					bDialog: false,
					bindWxDialog: false,
					wxpushDialog:false,
					edit: false,
					userSchool: true,
					userrow:{
					    msg:"查询中...",
					},
					form: {
						id: 0, //id                          
						platform: "zxjy",  //平台                 
						runType:2,   //运行方式             校友帮
						school: "",//学校
						schoolName: "",//学校               慧职教
						schoolId: "",//学校id
						url: "",//学校私立服务器
						addressName: "",//习讯云地址名称
						up_remark: "0",//上班打卡类型
						down_remark: "8",//下班打卡类型
						phone: "",  //手机号                  
						password: "",//密码
						name: "",//姓名
						gwName: "",//岗位名称
						customizedGwName: "",
						phone_name: "HUAWEI|HUAWEIELE AL00|11",//手机型号            职校家园
						country: "中国",//国家              工学云 校友帮
						province: "",//省市                 工学云 校友帮
						city: "",//县                       工学云 校友帮
						area: "",//区县                     工学云
						adcode: "",//地区编码               校友帮
						addressOld: "",//公司地址            职校家园 校友帮 慧职教
						officialAddress: "",//公司地址      黔职通
						jobAddress: "",//公司地址           工学云
						address: "",//打卡地址
						lat: "",//经纬度
						lng: "",//经纬度
						reason: "",//打卡备注               校友帮
						projectName: "",//项目关键词         校友帮
						desctext: "",//打卡备注             工学云
						randomLocation: true,//浮动打卡     工学云
						check_time: "",//打卡时间            职校家园
						up_check_time: "",//上班打卡时间     
						down_check_time: "",//下班打卡时间
						check_week: [],//打卡周期
						end_time: "",//结束时间
						projectStartTime: "",//项目开始时间   工学云
						nowWeekNum: 0,//今天是第几周        工学云
						wxpush: "",//微信推送
						day_paper: false,//日报开关
						week_paper: false,//周报开关
						month_paper: false,//月报开关
						weekPaperSubmitWeek: 7,//周报提交时间     职校家园
						monthPaperSubmitMonth: 0,//月报提交时间   黔直通
						paper_router: 2,//报告库选择
						payType: 0,//一次新订单
						holiday: false,
						paperNumSetting: {
                    		day: {
                    			minSize: 0,
                    			maxSize: 0
                    		},
                    		week: {
                    			minSize: 0,
                    			maxSize: 0
                    		},
                    		month: {
                    			minSize: 0,
                    			maxSize: 0
                    		},
                    		summary:{
                    		    minSize: 0,
                    			maxSize: 0
                    		}
                    	}
					},
					buPapersForm:{
					    id:0,
					    phone:"",
					    startTime:"",
					    endTime:"",
					    levelName:"日报"
					},
					bindWxForm:{
					    id:0,
					    phone:"",
					    platform:"xyb",
					    check_week:[],
					    end_time:""
					},
					cx: { qq: "", search: "phone" },
					platFormList: [
						{
							value: "zxjy",
							label: "职校家园",
						},
						{
							value: "cxy",
							label: "成学云",
						},
						{
							value: "gxy",
							label: "工学云",
						},
						{
							value: "xyb",
							label: "校友帮",
						},
						{
							value: "xxy",
							label: "习迅云&宁夏",
						},
						{
							value: "xxt",
							label: "学习通",
						},
						{
							value: "jxzhjy",
							label: "江西智慧教育",
						},
						{
							value: "hzj",
							label: "慧职教&锦医道",
						},
						{
							value: "gxzy",
							label: "广西职业",
						},
						{
							value: "bx",
							label: "博行",
						},
						{
							value: "qzt",
							label: "黔职通",
						},
					],
					formOther: {
						down_check: false,
						wxpush_img:""
					},
					buPapersFormRules:{
					    levelName:[
					        { required: true, message: '请选择类型', trigger: 'blur' },
				        ],
				        startTime:[
				            { required: true, message: '请选择时间', trigger: 'change' }
				            ],
				            endTime:[
				            { required: true, message: '请选择时间', trigger: 'change' }
				            ]
					},
					addFormRules: {
						platform: [
							{ required: true, message: '请选择打卡平台', trigger: 'blur' },
						],
						schoolId: [
							{ required: true, message: '请选择学校', trigger: 'blur' }
						],
						phone: [
							{ required: true, message: '请输入打卡账号', trigger: 'blur' }
						],
						password: [
							{ required: true, message: '请输入打卡密码', trigger: 'blur' }
						],
						country: [
							{ required: true, message: '请输入国家', trigger: 'blur' }
						],
						province: [
							{ required: true, message: '请输入省份', trigger: 'blur' }
						],
						city: [
							{ required: true, message: '请输入区/县', trigger: 'blur' }
						],
						area: [
							{ required: true, message: '请输入区/县', trigger: 'blur' }
						],
						adcode: [
							{ required: true, message: '请输入地区编码', trigger: 'blur' }
						],
						addressOld: [
							{ required: true, message: '请输入公司地址', trigger: 'blur' }
						],
						officialAddress: [
							{ required: true, message: '请输入打卡地址', trigger: 'blur' }
						],
						jobAddress: [
							{ required: true, message: '请输入打卡地址', trigger: 'blur' }
						],
						address: [
							{ required: true, message: '请输入打卡地址', trigger: 'blur' }
						],
						lat: [
							{ required: true, message: '请输入纬度', trigger: 'blur' }
						],
						lng: [
							{ required: true, message: '请输入经度', trigger: 'blur' }
						],
						check_time: [
							{ required: true, message: '请选择时间', trigger: 'change' }
						],
						up_check_time: [
							{ required: true, message: '请选择时间', trigger: 'change' }
						],
						down_check_time: [
							{ required: true, message: '请选择时间', trigger: 'change' }
						],
						check_week: [
							{ type: 'array', required: true, message: '请至少选择一个打卡日期', trigger: 'change' }
						],
						end_time: [
							{ required: true, message: '请选择日期', trigger: 'change' }
						],
					},
					tableData: [],
					xxySchoolList: [],
					xxtSchoolList: [],
					hzjSchoolList: [],
					xxyAddressPois: [],
					mark_list: [
						{
							key: "0",
							value: "上班",
						},
						{
							key: "1",
							value: "因公外出",
						},
						{
							key: "2",
							value: "假期",
						},
						{
							key: "3",
							value: "请假",
						},
						{
							key: "4",
							value: "轮岗",
						},
						{
							key: "5",
							value: "回校",
						},
						{
							key: "6",
							value: "外宿",
						},
						{
							key: "7",
							value: "在家",
						},
						{
							key: "8",
							value: "下班",
						},
						{
							key: "9",
							value: "学习",
						},
						{
							key: "10",
							value: "毕业设计",
						},
						{
							key: "11",
							value: "院区轮转",
						},
						{
							key: "13",
							value: "集训",
						},
						{
                          "key": 14,
                          "value": "休息"
                     	}
					],
					gxy_mark_list: [
						{ key: 1, value: "上班" },
						{ key: 2, value: "下班" },
						{ key: 3, value: "休息日/节假" },
					],
					noticeList: [],
					multipleSelection: [],
					batchType: "",
					batchList: [
						{
							value: 'batchOpenOrder',
							label: '批量启动订单'
						}, {
							value: 'batchCloseOrder',
							label: '批量暂停订单'
						},
						{
							value: 'batchOpenHoliday',
							label: '批量绑定法定节假日'
						},
						{           
							value: 'batchCloseHoliday',
							label: '批量解绑法定节假日'
						},
						{           
							value: 'batchDelOrder',
							label: '批量删除订单'
						},
						{
							value: 'batchNowCheck',
							label: '批量立即打卡'
						}

					],
					addCommentForm: {
                        question: '',
                        id: '',
                        phone:'',
                        platform:''
                    },
                    commentList: [],
				};
			},
			created() {
				this.handleResize();
			},
			beforeMount() { },
			mounted() {
			    this.xxySchoolList = (typeof xxySchoolBase !== 'undefined') ? xxySchoolBase : [];
                this.hzjSchoolList = (typeof hzjSchoolBase !== 'undefined') ? hzjSchoolBase : [];
			    this.get_price(this.form.platform);
				this.query();
				this.onResize()
                setTimeout(() => {
                    this.get_userrow();
                    this.getNotice();
    				
                }, 0.1);
                setTimeout(() => {
                    this.xxyGetSchoolList()
    				this.hzjGetSchoolList()
                }, 0.2);
				
			},
			watch: {
				'form.nowWeekNum'() {
					if (!this.dialog || this.form.platform !== 'gxy' || this._syncingGxyWeek) return;
					this.syncGxyProjectStartFromWeek();
				},
			},
			computed: {
				yuji: function () {
					if (this.form.end_time && Array.isArray(this.form.check_week) && this.form.check_week.length > 0) {
						let day = this.timeCalcTrueday(new Date().getTime(), this.form.end_time, this.form.check_week)
						if (this.form.platform=="xyb"&&this.form.runType==3){
						    day=day*5
						}
						return (this.price * day).toFixed(2) + "元";
					} else {
						return "0元"
					}
				},
				editYuji: function () {
					if (this.form.end_time && this.form.old_end_time && this.edit && Array.isArray(this.form.check_week) && this.form.check_week.length > 0) {
						let oldsjc = new Date(this.form.old_end_time + " 23:59:59").getTime();//01-01
						let tadysjc = new Date().getTime();//02-27
						let endSjc = new Date(this.form.end_time + " 23:59:59").getTime();//12-27
						let day;
						if (tadysjc >= oldsjc && tadysjc < endSjc) {
							//订单已过期续费
							let newDay = this.timeCalcTrueday(new Date().getTime(), this.form.end_time, this.form.check_week)
							day = newDay;
						} else {
							//订单未过期
							let nowsjc = oldsjc;//01-01

							if (endSjc <= nowsjc && Array.isArray(this.form.old_check_week) && Array.isArray(this.form.check_week) && this.form.old_check_week.length <= this.form.check_week.length) {
								return "0元"
							}
							let oldDay = this.timeCalcTrueday(new Date().getTime(), this.form.old_end_time, this.form.old_check_week)
							let newDay = this.timeCalcTrueday(new Date().getTime(), this.form.end_time, this.form.check_week)
							day = newDay - oldDay;
						}
						if (day < 0) {
							return "0元"
						}
						if (this.form.platform=='xyb'){
						    if (this.form.runType==3){
						        day=day*5
						    }
						}
						return (this.price * day).toFixed(2) + "元";
					} else {
						return "0元"
					}
				},
				bindWxMoney: function () {
					if (this.bindWxForm.end_time && Array.isArray(this.bindWxForm.check_week) && this.bindWxForm.check_week.length > 0) {
						let day = this.timeCalcTrueday(new Date().getTime(), this.bindWxForm.end_time, this.bindWxForm.check_week)
						return (this.price * day * 4).toFixed(2) + "元";
					} else {
						return "0元"
					}
				},
			},
			methods: {
				syncGxyProjectStartFromWeek() {
					const weekNum = Number(this.form.nowWeekNum);
					if (!weekNum || weekNum < 1 || Number.isNaN(weekNum)) return;
					const nowTime = Date.now();
					const weekTime = 7 * 24 * 3600 * 1000 * (weekNum - 1);
					const proT = new Date(nowTime - weekTime);
					if (Number.isNaN(proT.getTime())) return;
					this.form.projectStartTime = proT.toISOString().split("T")[0];
				},
				onGxyProjectStartChange() {
					if (this.form.platform !== 'gxy') return;
					this._syncingGxyWeek = true;
					if (!this.form.projectStartTime) {
						this.form.nowWeekNum = 0;
					} else {
						const nowTime = new Date().getTime();
						const proT = new Date(this.form.projectStartTime).getTime();
						const weekNum = Math.ceil((nowTime - proT) / (7 * 24 * 3600 * 1000));
						this.form.nowWeekNum = weekNum > 0 ? weekNum : 0;
					}
					this.$nextTick(() => {
						this._syncingGxyWeek = false;
					});
				},
				normalizeGxyOrderFields() {
					if (this.form.platform !== 'gxy') return;
					if (this.form.projectStartTime) {
						this.form.projectStartTime = String(this.form.projectStartTime).split(" ")[0];
						if (this.form.nowWeekNum === undefined || this.form.nowWeekNum === null || this.form.nowWeekNum === '') {
							this._syncingGxyWeek = true;
							this.form.nowWeekNum = Math.max(0, Math.ceil((Date.now() - new Date(this.form.projectStartTime).getTime()) / (7 * 24 * 3600 * 1000)));
							this.$nextTick(() => { this._syncingGxyWeek = false; });
						} else {
							this.form.nowWeekNum = Number(this.form.nowWeekNum) || 0;
						}
					} else {
						this.form.projectStartTime = "";
						this.form.nowWeekNum = 0;
					}
					const upRaw = String(this.form.up_remark == null ? '' : this.form.up_remark);
					const downRaw = String(this.form.down_remark == null ? '' : this.form.down_remark);
					const up = Number(upRaw.replace(/[^0-9]/g, '').charAt(0) || upRaw);
					const down = Number(downRaw.replace(/[^0-9]/g, '').charAt(0) || downRaw);
					this.form.up_remark = [1, 2, 3].includes(up) ? up : 1;
					this.form.down_remark = [1, 2, 3].includes(down) ? down : 2;
				},
				//计算输入日期距离当前日期的天数差-仅计算传入参数二数组包含的周几天数
				timeCalcTrueday(nowsjc, end_time, check_week) {
					if (check_week) {
						// 打卡周期0-6排序
						check_week.sort(function (a, b) {
							return a - b;
						});
						// 获取当前时间戳
						// let nowsjc = new Date().getTime();
						// 获取结束时间戳
						let endSjc = new Date(end_time + " 23:59:59").getTime();
						if(endSjc<nowsjc){
						    return 0
						}
						// 获取当前周几
						let nowWeekDay = this.getDayOfWeek(nowsjc);
						// 获取当前周周末时间戳
						let weekEndSjc = new Date(
							this.format(nowsjc + (6 - nowWeekDay) * 86400 * 1000) + " 23:59:59"
						).getTime();
						//获取本周大于等于今天周几的天数，且在打卡周期内的
						let nowWeekLast = check_week.filter((item) => {
							return Number(item) >= nowWeekDay;
						});
						
						
						//获取结束当天周几
						let endWeekDay = this.getDayOfWeek(endSjc);
						//判断结束时间戳是否小于等于本周周末时间戳
						if (endSjc <= weekEndSjc) {
							//结束时间在本周内
							//通过打卡周期内本周大于今天周几的数组，来获取结束日期前的周几数组
							let lastWeekLast = nowWeekLast.filter((item) => {
								return Number(item) <= endWeekDay;
							});
							//返回本周可打卡天数
							return lastWeekLast.length;
						} else {
							//结束时间不在本周内
							//获取结束周小于等于结束时间周几的天数，且在打卡周期内
							let endWeekLast = check_week.filter((item) => {
								return Number(item) <= endWeekDay;
							});
							//获取整周总时间戳
							let intSjc = endSjc - (endWeekDay + 1) * 86400 * 1000 - weekEndSjc;
							//返回本周打卡周期内天数+整周打卡周期内天数+结束周打卡周期内天数
							return (
								nowWeekLast.length +
								(intSjc / 7 / 86400 / 1000) * check_week.length +
								endWeekLast.length
							);
						}
					}
				},
				//获取指定时间戳是周几
				getDayOfWeek(timestamp) {
					let date = new Date(timestamp);
					let dayOfWeek = date.getDay();
					let weekdays = [7, 1, 2, 3, 4, 5, 6];
					return weekdays[dayOfWeek] - 1;
				},
				//将时间戳转换为年月日时间
				format(dataString) {
					//dataString是整数，否则要parseInt转换
					var time = new Date(dataString);
					var year = time.getFullYear();
					var month = time.getMonth() + 1;
					var day = time.getDate();
					return (
						year +
						"-" +
						(month < 10 ? "0" + month : month) +
						"-" +
						(day < 10 ? "0" + day : day)
					);
				},
				handleSelectionChange(val) {
					this.multipleSelection = val;
					console.log(this.multipleSelection)
				},
				getPlatformLabel(platform) {
					const item = this.platFormList.find(item => item.value === platform);
					return item ? item.label : (platform || "未知");
				},
				getBatchOperationTitle(type) {
					const item = this.batchList.find(item => item.value === type);
					return item ? item.label : "批量操作";
				},
				getOrderPlatformText(row) {
					let text = this.getPlatformLabel(row.platform);
					if (row.platform === "xyb") {
						const runMap = { 1: "(抓包)", 2: "(账密1号)", 3: "(绑微1号)" };
						text += runMap[row.runType] || "";
					}
					return text;
				},
				formatCheckWeekShort(check_week) {
					if (!check_week) return "-";
					const weekStr = Array.isArray(check_week) ? check_week.join(",") : String(check_week);
					const names = ["一", "二", "三", "四", "五", "六", "日"];
					return weekStr.split(",")
						.filter(w => w !== "")
						.map(w => "周" + (names[Number(w)] || w))
						.join("") || "-";
				},
				openAddDialog() {
					this.edit = false;
					this.resetForm();
					this.dialog = true;
				},
				getEndTimeClass(end_time) {
					if (!end_time) return "";
					const end = new Date(end_time + " 23:59:59").getTime();
					const now = Date.now();
					const days = (end - now) / 86400000;
					if (days < 0) return "end-time-expired";
					if (days <= 3) return "end-time-warning";
					return "";
				},
				isMobileRowSelected(row) {
					return this.multipleSelection.some(i => i.id === row.id);
				},
				toggleMobileSelection(row) {
					if (this.isMobileRowSelected(row)) {
						this.multipleSelection = this.multipleSelection.filter(i => i.id !== row.id);
					} else {
						this.multipleSelection = [...this.multipleSelection, row];
					}
				},
				openMobileActions(row) {
					this.mobileActionItem = row;
					this.mobileActionDrawerVisible = true;
				},
				runMobileAction(type) {
					const item = this.mobileActionItem;
					this.mobileActionDrawerVisible = false;
					if (item) {
						this.handleCommand({ item, type });
					}
				},
				batchDialogBeforeClose(done) {
					if (this.batchRunning) {
						this.$message.warning("批量任务执行中，请等待完成");
						return;
					}
					done();
				},
				scrollBatchLogToBottom() {
					this.$nextTick(() => {
						const table = this.$refs.batchLogTable;
						if (table && table.bodyWrapper) {
							table.bodyWrapper.scrollTop = table.bodyWrapper.scrollHeight;
						}
						const mobileWrap = this.$refs.batchLogMobileWrap;
						if (mobileWrap) {
							mobileWrap.scrollTop = mobileWrap.scrollHeight;
						}
					});
				},
				async batchFuntion() {
					if (this.multipleSelection.length == 0) {
						this.$message.error("未选择任何订单");
						return
					}
					if (this.batchType == "") {
						this.$message.error("未选择需要执行的操作");
						return
					}
					if (this.batchType == "batchNowCheck") {
						try {
							await this.$confirm(
								`已选择 ${this.multipleSelection.length} 个订单，每个打卡成功将扣除该平台一天余额，是否继续？`,
								'批量立即打卡提示',
								{ confirmButtonText: '立即打卡', cancelButtonText: '取消', type: 'warning' }
							);
						} catch (e) {
							return;
						}
					}

					this.batchRunningTitle = this.getBatchOperationTitle(this.batchType);
					this.batchLogs = [];
					this.batchTotal = this.multipleSelection.length;
					this.batchCompleted = 0;
					this.batchProgress = 0;
					this.batchSuccessCount = 0;
					this.batchFailCount = 0;
					this.batchRunning = true;
					this.batchDialogVisible = true;
					this.batchLoading = true;

					const orders = JSON.parse(JSON.stringify(this.multipleSelection));
					try {
						for (const [index, item] of orders.entries()) {
							let url = ""
							if (this.batchType == "batchOpenOrder") {
								item["code"] = 1
								url = "/sxdk/api.php?act=changeCheckCode"
							}
							else if (this.batchType == "batchCloseOrder") {
								item["code"] = 0
								url = "/sxdk/api.php?act=changeCheckCode"
							}
							else if (this.batchType == "batchOpenHoliday") {
								item["code"] = 1
								url = "/sxdk/api.php?act=changeHolidayCode"
							}
							else if (this.batchType == "batchCloseHoliday") {
								item["code"] = 0
								url = "/sxdk/api.php?act=changeHolidayCode"
							}
							else if (this.batchType == "batchDelOrder") {
								url = "/sxdk/api.php?act=del"
							}
							else if (this.batchType == "batchNowCheck") {
								url = "/sxdk/api.php?act=nowCheck"
							}
							else {
								break
							}

							let success = false;
							let msg = "";
							try {
								let resp = await this.$http.post(
									url,
									{ ...item },
									{ emulateJSON: true }
								);
								success = resp.body.code == 0;
								msg = resp.body.msg || (success ? "执行成功" : "执行失败");
							} catch (e) {
								msg = "请求异常，请稍后重试";
							}

							if (success) {
								this.batchSuccessCount++;
							} else {
								this.batchFailCount++;
							}

							this.batchLogs.push({
								index: index + 1,
								phone: item.phone || "-",
								name: item.name || "-",
								platform: this.getPlatformLabel(item.platform),
								status: success ? "成功" : "失败",
								msg: msg,
								time: new Date().toLocaleTimeString()
							});

							this.batchCompleted = index + 1;
							this.batchProgress = Math.round((index + 1) / this.batchTotal * 100);
							this.scrollBatchLogToBottom();
						}
					} finally {
						this.batchRunning = false;
						this.batchLoading = false;
						this.query();
					}
				},
				resetForm() {
					this.form = {
						id: 0, //id                          
						platform: "zxjy",  //平台                 
						runType:1,
						school: "",//学校
						schoolName: "",//学校               慧职教
						schoolId: "",//学校id
						url: "",//学校私立服务器
						addressName: "",//习讯云地址名称
						up_remark: "0",//上班打卡类型
						down_remark: "8",//下班打卡类型
						phone: "",  //手机号                  
						password: "",//密码
						name: "",//姓名
						gwName: "",//岗位名称
						customizedGwName: "",
						phone_name: "HUAWEI|HUAWEIELE AL00|11",//手机型号            职校家园
						country: "中国",//国家              工学云 校友帮
						province: "",//省市                 工学云 校友帮
						city: "",//县                       工学云 校友帮
						area: "",//区县                     工学云
						adcode: "",//地区编码               校友帮
						addressOld: "",//公司地址            职校家园 校友帮 慧职教
						officialAddress: "",//公司地址      黔职通
						jobAddress: "",//公司地址           工学云
						address: "",//打卡地址
						lat: "",//经纬度
						lng: "",//经纬度
						reason: "",//打卡备注               校友帮
						projectName: "",//项目关键词         校友帮
						desctext: "",//打卡备注             工学云
						randomLocation: true,//浮动打卡     工学云
						check_time: "",//打卡时间            职校家园
						up_check_time: "",//上班打卡时间     
						down_check_time: "",//下班打卡时间
						check_week: [],//打卡周期
						end_time: "",//结束时间
						projectStartTime: "",//项目开始时间   工学云
						nowWeekNum: 0,//今天是第几周        工学云
						wxpush: "",//微信推送
						day_paper: false,//日报开关
						week_paper: false,//周报开关
						month_paper: false,//月报开关
						weekPaperSubmitWeek: 7,//周报提交时间     职校家园
						monthPaperSubmitMonth: 0,//月报提交时间   黔直通
						paper_router: 2,//报告库选择
						payType: 0,//一次新订单
						holiday: false,
						paperNumSetting : {
            				day: {
            					minSize: 0,
            					maxSize: 0
            				},
            				week: {
            					minSize: 0,
            					maxSize: 0
            				},
            				month: {
            					minSize: 0,
            					maxSize: 0
            				},
            				summary: {
            					minSize: 0,
            					maxSize: 0
            				}
            			}
					},
					this.formOther.down_check = false
				},
				
				BresetForm(){
				    this.buPapersForm={
					    id:0,
					    phone:"",
					    startTime:"",
					    endTime:"",
					    levelName:"日报"
					}
				},
				bingWxresetForm(){
				    this.bindWxForm={
					    id:0,
					    phone:"",
					    platform:"xyb",
					    check_week:[],
					    end_time:""
					}
				},
				addCommentresetForm(){
				    this.addCommentForm={
					    question: '',
                        id: '',
                        phone:'',
                        platform:''
					}
					this.commentList=[]
				},
				getStatusText(status) {
                  const statusMap = {
                    1: '待处理',
                    2: '处理中',
                    3: '已回复',
                    4: '等待客服介入'
                  };
                  return statusMap[status] || '未知状态';
                },
                
                // 获取状态标签类型
                getStatusTagType(status) {
                  const typeMap = {
                    1: 'warning',
                    2: 'info',
                    3: 'success',
                    4: 'warning'
                  };
                  return typeMap[status] || 'danger';
                },
                showAnswer(item){
                    const htmlText = `
                      <div style="padding: 16px; background: #f5f7fa; border-radius: 4px;">
                        <p style="font-weight: bold; color: #409eff; margin-bottom: 8px;">【${item.platform?item.platform:'全局'}】Q: ${item.question}</p>
                        <p style="color: #666;">A: ${item.answer}</p>
                      </div>
                    `;
                    
                    // 调用弹窗
                    this.$alert(htmlText, "在线反馈", {
                      dangerouslyUseHTMLString: true, // 允许 HTML 渲染
                      confirmButtonText: '确定',
                      customClass: 'single-qa-alert' // 可选：自定义样式
                    });
                },
				picUpload() {
					this.$confirm(`支持：打卡、立即打卡、日报、周报、月报、补卡/报告<br/><br/><p style='color:red'>图片数据库网址：http://location.copilotai.top:4000/#/picDatabases</p>`, '打卡图片上传', {
						distinguishCancelAndClose: true,
						dangerouslyUseHTMLString: true,
						confirmButtonText: '跳转',
						cancelButtonText: '取消'
					})
						.then(() => {
							window.open('http://location.copilotai.top:4000/#/picDatabases', "_blank");
						})
						.catch(action => { });
				},
				xxyschoolChange(value) {
					let checkSchools = this.xxySchoolList.filter((item) => {
						return item.value == value
					})
					if (checkSchools.length > 0) {
						checkSchool = checkSchools[0]
						this.form.school = checkSchool.text
						this.form.url = checkSchool.url
						this.form.schoolId = checkSchool.value
					} else {
						this.$message.error("不存在的学校");
						this.form.school = ""
						this.form.url = ""
						this.form.schoolId = ""
					}
				},
				xxyCheckAddressChange(value) {
					let checkAddresss = this.xxyAddressPois.filter((item) => {
						return item.value == value
					})
					if (checkAddresss.length > 0) {
						checkAddress = checkAddresss[0]
						this.form.address = checkAddress.value
						this.form.addressName = checkAddress.addressName
						this.form.lat = checkAddress.lat
						this.form.lng = checkAddress.lng
					} else {
						this.$message.error("不存在的地址");
					}
				},
				xxtschoolChange(value) {
					let checkSchools = this.xxtSchoolList.filter((item) => {
						return item.value == value
					})
					if (checkSchools.length > 0) {
						checkSchool = checkSchools[0]
						this.form.school = checkSchool.text
						this.form.schoolId = checkSchool.value
					} else {
						this.$message.error("不存在的学校");
						this.form.school = ""
						this.form.schoolId = ""
					}
				},
				xxtLoginTypeChange(value) {
					if (value) {
						this.form.schoolId = ""
						this.form.school = ""
					}
				},
				hzjschoolChange(value) {
					let checkSchools = this.hzjSchoolList.filter((item) => {
						return item.value == value
					})
					if (checkSchools.length > 0) {
						checkSchool = checkSchools[0]
						this.form.schoolName = checkSchool.text
						this.form.schoolId = checkSchool.value
					} else {
						this.$message.error("不存在的学校");
						this.form.schoolName = ""
						this.form.schoolId = ""
					}
				},
				getPicDatabaseUrl(item) {
					const phone = encodeURIComponent(item.phone || "");
					const password = encodeURIComponent(item.password || "");
					const platform = encodeURIComponent(item.platform || "");
					return `http://location.copilotai.top:4000/#/picDatabases?phone=${phone}&password=${password}&platform=${platform}`;
				},
				copyPicDatabaseUrl(item) {
					const url = this.getPicDatabaseUrl(item);
					if (navigator.clipboard && navigator.clipboard.writeText) {
						navigator.clipboard.writeText(url).then(() => {
							this.$message.success("专属图库地址已复制");
						}).catch(() => {
							this.fallbackCopyText(url);
						});
					} else {
						this.fallbackCopyText(url);
					}
				},
				fallbackCopyText(text) {
					const input = document.createElement("textarea");
					input.value = text;
					input.style.position = "fixed";
					input.style.opacity = "0";
					document.body.appendChild(input);
					input.select();
					try {
						document.execCommand("copy");
						this.$message.success("专属图库地址已复制");
					} catch (e) {
						this.$message.error("复制失败，请手动复制");
					}
					document.body.removeChild(input);
				},
				handleCommand: async function (data) {
					if (data.type == "log") {
						this.getLog(data.item)
					}
					if (data.type == "copyPicUrl") {
						this.copyPicDatabaseUrl(data.item)
					}
					if (data.type == "buPapers") {
					    this.buPapersForm.id=data.item.id
					    this.buPapersForm.phone=data.item.phone
					    this.buPapersForm.platform=data.item.platform
					    if(this.buPapersForm.platform=="qzt"){
					        this.buPapersForm.levelName="周报"
					    }
						this.bDialog=true
					}
					if (data.type == "bingWx") {
					    let sourceOrder = await this.querySourceOrder(data.item)
					    if (sourceOrder) {
					        console.log(sourceOrder)
    					    this.bindWxForm.id=data.item.id
    					    this.bindWxForm.phone=sourceOrder.phone
    					    this.bindWxForm.platform=data.item.platform
    					    this.bindWxForm.end_time=sourceOrder.end_time
    					    this.bindWxForm.check_week=sourceOrder.check_week ? sourceOrder.check_week.split(",") : []
    					    this.get_price(data.item.platform)
    						this.bindWxDialog=true
					    }
					}
					
					if (data.type == "getAsyncTask") {
						this.getAsyncTask(data.item)
					}
					if (data.type == "getCommentList") {
					    this.addCommentForm.id=data.item.id
					    this.addCommentForm.phone=data.item.phone
					    this.addCommentForm.platform=data.item.platform
					    this.commentList = this.commentList || [];
						this.addCommentDialogVisible=true;
						this.getCommentList(data.item)
					}
					if (data.type == "del") {
						this.deleteOrder(data.item)
					}
					if (data.type == "nowCheck") {
						this.nowCheck(data.item)
					}
					if (data.type == "changeCheckCode") {
						this.changeCheckCode(data.item)
					}
					if (data.type == "showErWeiMa") {
						this.getWxPush(data.item)
						this.form.id=data.item.id
                        const regex = /^https:\/\/push\.showdoc\.com\.cn\/server\/api\/push\/.+$/;
                    	if (regex.test(data.item.wxpush)) {
                    		this.form.wxpush = data.item.wxpush;
                    	} else {
                    		this.form.wxpush=""
                    	}
						this.wxpushDialog=true
					}
					if (data.type == "editOrderInfo") {
                        this.get_price(data.item.platform)
						let sourceOrder = await this.querySourceOrder(data.item)
						if (sourceOrder) {
							this.form = {
								...sourceOrder,
								platform: data.item.platform,
							}
							this.form.id = data.item.id
							this.form.old_end_time = sourceOrder.end_time;
							this.form.old_check_week = sourceOrder.check_week ? sourceOrder.check_week.split(",") : [];
							this.form.check_week = sourceOrder.check_week ? sourceOrder.check_week.split(",") : [];
							this.form.holiday = sourceOrder.holiday == 1;
							this.form.day_paper = sourceOrder.day_paper == 1;
							this.form.week_paper = sourceOrder.week_paper == 1;
							this.form.month_paper = sourceOrder.month_paper == 1;
							this.form.weekPaperSubmitWeek = sourceOrder.weekPaperSubmitWeek + 1;
							if(sourceOrder.paperNumSetting){
							    try {
                        			const paperNumSetting = JSON.parse(sourceOrder.paperNumSetting);
                        			this.form.paperNumSetting = paperNumSetting;
                        		} catch (e) {
                        			this.form.paperNumSetting = {
                        				day: {
                        					minSize: 0,
                        					maxSize: 0
                        				},
                        				week: {
                        					minSize: 0,
                        					maxSize: 0
                        				},
                        				month: {
                        					minSize: 0,
                        					maxSize: 0
                        				},
                        				summary: {
                        					minSize: 0,
                        					maxSize: 0
                        				}
                        			};
                        		}
							}else{
							    this.form.paperNumSetting = {
                        				day: {
                        					minSize: 0,
                        					maxSize: 0
                        				},
                        				week: {
                        					minSize: 0,
                        					maxSize: 0
                        				},
                        				month: {
                        					minSize: 0,
                        					maxSize: 0
                        				},
                        				summary: {
                        					minSize: 0,
                        					maxSize: 0
                        				}
                        			};
							}
							
							if (sourceOrder.down_check_time) {
								this.formOther.down_check = true
							}
							this.normalizeGxyOrderFields();
							this.edit = true;
							
							this.dialog = true;
							console.log(this.form)
						}
					}
				},
				getPhoneInfo: function () {
					this.getPhoneInfoLoading = true
					this.$http
						.post(
							"/sxdk/api.php?act=searchPhoneInfo",
							{
								...this.form
							},
							{ emulateJSON: true }
						)
						.then((res) => {
							if (res.data.code == 0) {
								if (this.form.platform == "zxjy") {
									this.form.name = res.data.data.name
									this.form.address = res.data.data.address
									this.form.gwName = res.data.data.gwName
									this.form.customizedGwName = res.data.data.gwName
									this.form.lat = res.data.data.lat
									this.form.lng = res.data.data.lng
									this.form.addressOld = res.data.data.addressOld
									this.$message.success(res.data.msg + "\n信息获取成功，请补充其余必填信息");
								} else if (this.form.platform == "qzt") {
									this.form.name = res.data.data.name;
									this.form.up_check_time = res.data.data.checkInTime;
									this.form.down_check_time = res.data.data.checkOutTime;
									this.form.end_time = res.data.data.endTime;
									this.form.lng = res.data.data.longitude;
									this.form.lat = res.data.data.latitude;
									this.form.officialAddress = res.data.data.address;
									this.form.address = res.data.data.officialAddress;
									this.form.gwName = res.data.data.gwName;
									let weekList = res.data.data.weekList.split(",");
									weekList = weekList.map((item) => {
										if (Number(item) > 1) {
											return (Number(item) - 2).toString();
										} else if (Number(item) == 1) {
											return "6";
										}
									});
									this.form.check_week = weekList;
									this.$message.success(res.data.msg + "\n信息获取成功，请补充其余必填信息");
								} else if (this.form.platform == "gxy") {
									this.form.name = res.data.data.name;
									this.form.gwName = res.data.data.jobName;
									this.form.lng = res.data.data.lng;
									this.form.lat = res.data.data.lat;
									this.form.jobAddress = res.data.data.jobAddress;
									this.form.address = res.data.data.address;
									this.form.province = res.data.data.jobProvince;
									this.form.city = res.data.data.jobCity;
									this.form.area = res.data.data.jobArea;
									this.form.projectName = res.data.data.planName;
									if (res.data.data.projectStartTime) {
										this.form.projectStartTime = String(res.data.data.projectStartTime).split(" ")[0];
										this.onGxyProjectStartChange();
									}
									this.$message.success(res.data.msg + "\n信息获取成功，请补充其余必填信息");
								} else if (this.form.platform == "xyb") {
									this.form.addressOld = res.data.data.addressOld;
									this.form.address = res.data.data.address;
									this.form.lng = res.data.data.lng;
									this.form.lat = res.data.data.lat;
									this.form.adcode = res.data.data.adcode;
									this.form.province = res.data.data.province;
									this.form.country = res.data.data.country;
									this.form.city = res.data.data.city;
									this.form.day_paper = res.data.data.checkOrder.needDailyBlogs;
									this.form.week_paper = res.data.data.checkOrder.needWeeklyBlogs;
									this.form.month_paper = res.data.data.checkOrder.needMonthlyBlogs;
									this.form.gwName = res.data.data.checkOrder.gwName;
									this.form.name = res.data.data.checkOrder.name;
									this.form.projectName = res.data.data.checkOrder.planName;
									if (res.data.data.checkOrder.clockRuleType == 1) {
										this.formOther.down_check = true;
										this.$confirm('信息获取成功，请补充其余必填信息，需要下班打卡，请不要忘记设置。', '提示', {
											confirmButtonText: '确定',
											cancelButtonText: '取消',
											type: 'warning'
										}).then(() => {
											this.$message.success(res.data.msg + "\n信息获取成功，请补充其余必填信息");
										}).catch(() => {
											this.$message.success(res.data.msg + "\n信息获取成功，请补充其余必填信息");
										});
									} else {
										this.form.down_check_time = "";
										this.formOther.down_check = false;
										this.$confirm('信息获取成功，请补充其余必填信息，无需下班打卡', '提示', {
											confirmButtonText: '确定',
											cancelButtonText: '取消',
											type: 'warning'
										}).then(() => {
											this.$message.success(res.data.msg + "\n信息获取成功，请补充其余必填信息");
										}).catch(() => {
											this.$message.success(res.data.msg + "\n信息获取成功，请补充其余必填信息");
										});
									}
								} else if (this.form.platform == "xxy") {
									this.form.name = res.data.data.name;
									this.form.lng = res.data.data.lng;
									this.form.lat = res.data.data.lat;
									this.form.province = res.data.data.province;
									this.form.city = res.data.data.city;
									this.form.addressOld = res.data.data.addressOld;
									this.form.gwName = res.data.data.gwName;
									this.form.city = res.data.data.city;
									this.form.day_paper = res.data.data.day_paper;
									this.form.week_paper = res.data.data.week_paper;
									this.form.month_paper = res.data.data.month_paper;
									this.xxyAddressPois = res.data.data.addressPois.map((item) => {
										return {
											...item,
											textValue: item.value + item.text,
										};
									});
									if (res.data.data.addressPois.length > 0) {
										this.form.address = res.data.data.address;
										this.form.addressName = res.data.data.addressName;
									}
									this.$message.success(res.data.msg + "\n信息获取成功，请补充其余必填信息");
								} else if (this.form.platform == "xxt") {
									this.form.name = res.data.data.name;
									this.form.lng = res.data.data.lng;
									this.form.lat = res.data.data.lat;
									this.form.address = res.data.data.address;
									this.form.addressOld = res.data.data.addressOld;
									this.form.gwName = res.data.data.gwName;
									this.form.day_paper = res.data.data.day_paper;
									this.form.week_paper = res.data.data.week_paper;
									this.form.month_paper = res.data.data.month_paper;
									this.form.projectName = res.data.data.planName;
									if (res.data.data.up_check_time && res.data.data.down_check_time) {
										this.formOther.down_check = true;
										this.form.up_check_time = res.data.data.up_check_time;
										this.form.down_check_time = res.data.data.down_check_time;
										this.$message.success(res.data.msg + "\n信息获取成功，请补充其余必填信息");
									} else {
										this.form.down_check_time = "";
										this.formOther.down_check = false;
										this.$message.success(res.data.msg + "\n信息获取成功，请补充其余必填信息，无需下班打卡");
									}
								} else if (this.form.platform == "hzj") {
									this.form.name = res.data.data.name;
									this.form.lng = res.data.data.lng;
									this.form.lat = res.data.data.lat;
									this.form.address = res.data.data.address;
									this.form.addressOld = res.data.data.addressOld;
									this.form.gwName = res.data.data.gwName;
									this.form.day_paper = res.data.data.day_paper == 1;
									this.form.week_paper = res.data.data.week_paper == 1;
									this.form.month_paper = res.data.data.month_paper == 1;
									this.$message.success(res.data.msg + "\n信息获取成功，请补充其余必填信息");
								} else if (this.form.platform == "gxzy") {
									this.form.name = res.data.data.name;
									this.form.lng = res.data.data.lng;
									this.form.lat = res.data.data.lat;
									this.form.address = res.data.data.address;
									this.form.addressOld = res.data.data.addressOld;
									this.form.gwName = res.data.data.gwName;
									this.form.day_paper = res.data.data.day_paper == 2;
									this.form.week_paper = res.data.data.week_paper == 2;
									this.form.month_paper = res.data.data.month_paper == 2;
									this.form.projectName = res.data.data.planName;
									this.$message.success(res.data.msg + "\n信息获取成功，请补充其余必填信息");
								} else if (this.form.platform == "jxzhjy") {
									this.form.name = res.data.data.name;
									this.form.lng = res.data.data.lng;
									this.form.lat = res.data.data.lat;
									this.form.address = res.data.data.address;
									this.form.addressOld = res.data.data.addressOld;
									this.form.gwName = res.data.data.gwName;
									this.form.day_paper = res.data.data.day_paper == 1;
									this.form.week_paper = res.data.data.week_paper == 1;
									this.form.month_paper = res.data.data.month_paper == 1;
									this.form.projectName = res.data.data.planName;
									this.$message.success(res.data.msg + "\n信息获取成功，请补充其余必填信息");
								
								} else if (this.form.platform == "cxy") {
									this.form.name = res.data.data.name;
									this.form.projectName = res.data.data.planName;
									this.form.gwName = res.data.data.gwName;
									
									this.form.address = res.data.data.address;
									this.form.addressOld = res.data.data.addressOld;
									this.form.lng = res.data.data.lng;
									this.form.lat = res.data.data.lat;

									this.form.day_paper = res.data.data.day_paper == 1;
									this.form.week_paper = res.data.data.week_paper == 1;
									this.form.month_paper = res.data.data.month_paper == 1;
									
									this.$message.success(res.data.msg + "\n信息获取成功，请补充其余必填信息");
								} 
								else if (this.form.platform == "bx") {
									this.form.name = res.data.data.name;
									this.form.projectName = res.data.data.planName;
									this.form.gwName = res.data.data.gwName;
									this.form.address = res.data.data.address;
									this.form.addressOld = res.data.data.addressOld;
									this.form.lng = res.data.data.lng;
									this.form.lat = res.data.data.lat;
									this.form.province = res.data.data.province;
									this.form.city = res.data.data.city;
									this.form.area = res.data.data.area;

									this.form.day_paper = res.data.data.day_paper == 1;
									this.form.week_paper = res.data.data.week_paper == 1;
									this.form.month_paper = res.data.data.month_paper == 1;

									this.$message.success(res.data.msg + "\n信息获取成功，请补充其余必填信息");
								} else {
									this.$message.error("未匹配到项目");
								}
							} else {
								this.$message.error(res.data.msg);
							}
							this.getPhoneInfoLoading = false
						}).catch((e) => {
							this.$message.error("网络错误，超时");
							this.getPhoneInfoLoading = false
						});
				},
				query: function () {
					this.loading = true;
					data = { cx: this.cx, page: this.currentpage, size: this.pagesize };
					this.$http
						.post("/sxdk/api.php?act=order", data, { emulateJSON: true })
						.then(function (data) {
							if (data.data.code == "0") {
								this.pagecount = Number(data.body.count);
								this.tableData = data.body.data || [];
							} else {
								this.$message.error(data.data.msg);
							}
						})
						.catch(function () {
							vm.$message.error("网络错误，请稍后重试");
						})
						.then(function () {
							this.loading = false;
						});
				},
				get_price(platform) {
					if (this.form.platform == "xyb" || this.form.platform == "zxjy" || this.form.platform == "xxy" || this.form.platform == "xxt" || this.form.platform == "hzj") {
						this.formOther.down_check = false
						this.form.down_check_time = ""
					} else {
						this.formOther.down_check = true
						this.form.down_check_time = ""
					}
					if (platform == "gxy") {
						this.form.up_remark = 1;
						this.form.down_remark = 2;
					} else if (platform == "xxy") {
						this.form.up_remark = "0";
						this.form.down_remark = "8";
					}
					this.$http
						.post(
							"/sxdk/api.php?act=price",
							{ platform: platform },
							{ emulateJSON: true }
						)
						.then(function (data) {
							this.price = data.data.data;
						});
				},
				get_userrow(){
				    this.$http
						.post(
							"/sxdk/api.php?act=get_userrow",
							{ },
							{ emulateJSON: true }
						)
						.then(function (data) {
							this.userrow = data.data.data;
						});
				},
				add: function () {
					this.$refs["Form"].validate((valid) => {
						if (valid) {
							this.addloading = true;
							if (this.edit) {
								this.$http.post(
									"/sxdk/api.php?act=edit",
									{
										form: {
											...this.form,
											paperNumSetting: JSON.stringify(this.form.paperNumSetting),
											check_week: this.form.check_week.join(","),
											holiday: this.form.holiday ? 1 : 2,
											day_paper: this.form.day_paper ? 1 : 2,
											week_paper: this.form.week_paper ? 1 : 2,
											month_paper: this.form.month_paper ? 1 : 2,
											weekPaperSubmitWeek: this.form.weekPaperSubmitWeek - 1,
											randomLocation: this.form.randomLocation ? 1 : 2,
											up_remark: String(this.form.up_remark),
											down_remark: String(this.form.down_remark),
										}
									},
									{ emulateJSON: true }
								)
									.then(function (data) {
										this.query();
										if (data.data.code == 0) {
											vm.$message.success(data.data.msg);
											this.resetForm()
											this.dialog = false;
										} else {
											vm.$message.error(data.data.msg);
										}
									})
									.catch(function () {
										vm.$message.error("网络错误，请稍后重试");
									})
									.then(function () {
										this.addloading = false;
									});
							} else {
								this.$http.post(
									"/sxdk/api.php?act=add",
									{
										form: {
											...this.form,
											paperNumSetting: JSON.stringify(this.form.paperNumSetting),
											check_week: this.form.check_week.join(","),
											holiday: this.form.holiday ? 1 : 2,
											day_paper: this.form.day_paper ? 1 : 2,
											week_paper: this.form.week_paper ? 1 : 2,
											month_paper: this.form.month_paper ? 1 : 2,
											weekPaperSubmitWeek: this.form.weekPaperSubmitWeek - 1,
											randomLocation: this.form.randomLocation ? 1 : 2,
											up_remark: String(this.form.up_remark),
											down_remark: String(this.form.down_remark),
										}
									},
									{ emulateJSON: true }
								)
									.then(function (data) {
										this.query();
										if (data.data.code == 0) {
											vm.$message.success(data.data.msg);
											this.resetForm()
											this.dialog = false;
										} else {
											vm.$message.error(data.data.msg);
										}
									})
									.catch(function () {
										vm.$message.error("网络错误，请稍后重试");
									})
									.then(function () {
										this.addloading = false;
									});
							}
						} else {
							this.$message.error("请填写全部必写内容");
							return false;
						}
					});

				},
				sizechange: function (val) {
					this.pagesize = val;
					this.query();
				},
				pagechange: function (val) {
					this.currentpage = val;
					this.query();
				},
				handleClose: function (done) {
					this.resetForm()
					done();
				},
				BhandleClose: function (done) {
					this.BresetForm()
					done();
				},
				bindWxhandleClose: function (done) {
					this.bingWxresetForm()
					done();
				},
				wxpushhandleClose: function (done) {
					this.resetForm()
					this.formOther.wxpush_img=""
					done();
				},
				addCommenthandleClose: function (done) {
					this.addCommentresetForm()
					done();
				},
				downCheckChange(val) {
					if (!val) this.form.down_check_time = ""
				},
				deleteOrder: function (item) {
					this.$confirm(`确定要删除订单：${item.phone}`, '删除警告', {
						distinguishCancelAndClose: true,
						confirmButtonText: '删除',
						cancelButtonText: '取消'
					})
						.then(() => {
							this.actionLoading = true;
							this.$http.post(
								"/sxdk/api.php?act=del",
								{
									...item
								},
								{ emulateJSON: true }
							)
								.then(function (data) {
									this.query();
									if (data.data.code == 0) {
										vm.$message.success(data.data.msg);
									} else {
										vm.$message.error(data.data.msg);
									}
								})
								.catch(function () {
									vm.$message.error("网络错误，请稍后重试");
								})
								.then(function () {
									this.actionLoading = false;
								});
						})
						.catch(action => { });
				},
				getLog: function (item) {
					this.actionLoading = true;
					this.$http.post(
						"/sxdk/api.php?act=getLog",
						{
							...item
						},
						{ emulateJSON: true }
					)
						.then(function (data) {
							if (data.data.code == 0) {
								let htmlText = data.data.data.map((i) => {
									return `<p>时间：${i.logTime}<br/>类型：${i.logType}<br/>内容：${i.logText}</p><hr/>`
								}).join("")
								this.$alert(htmlText, `${item.phone}的最近10条日志`, {
									dangerouslyUseHTMLString: true
								});
							} else {
								vm.$message.error(data.data.msg);
							}
						})
						.catch(function () {
							vm.$message.error("网络错误，请稍后重试");
						})
						.then(function () {
							this.actionLoading = false;
						});
				},
				nowCheck: function (item) {
					this.$confirm(`账号：${item.phone}，现在要立即打卡么？打卡成功将会扣除该项目一天的余额`, '立即打卡提示', {
						distinguishCancelAndClose: true,
						confirmButtonText: '立即打卡',
						cancelButtonText: '取消'
					})
						.then(() => {
							this.actionLoading = true;
							this.$http.post(
								"/sxdk/api.php?act=nowCheck",
								{
									...item
								},
								{ emulateJSON: true }
							)
								.then(function (data) {
									this.query();
									if (data.data.code == 0) {
										vm.$message.success(data.data.msg);
									} else {
										vm.$message.error(data.data.msg);
									}
								})
								.catch(function () {
									vm.$message.error("网络错误，请稍后重试");
								})
								.then(function () {
									this.actionLoading = false;
								});
						})
						.catch(action => { });

				},
				buPapers:function(){
				    	this.$refs["BForm"].validate((valid) => {
						if (valid) {
							this.buPapersloading = true;
							this.$http.post(
								"/sxdk/api.php?act=buPapers",
								{
									...this.buPapersForm
								},
								{ emulateJSON: true }
							)
								.then(function (data) {
									if (data.data.code == 0) {
    							        this.BresetForm()
										vm.$message.success(data.data.msg);
										this.bDialog = false;
									} else {
										vm.$message.error(data.data.msg);
									}
								})
								.catch(function () {
									vm.$message.error("网络错误，请稍后重试");
								})
								.then(function () {
									this.buPapersloading = false;
								});
						} else {
							this.$message.error("请填写全部必写内容");
							return false;
						}
					});
				    
				},
				xybBindWx:function(){
					this.bindWxLoading = true;
					this.$http.post(
						"/sxdk/api.php?act=xybBindWx",
						{
							...this.bindWxForm
						},
						{ emulateJSON: true }
					)
					.then(function (data) {
						if (data.data.code == 0) {
						    this.BresetForm()
							vm.$message.success(data.data.msg);
							this.bindWxDialog=false
						} else {
							vm.$message.error(data.data.msg);
						}
					})
					.catch(function () {
						vm.$message.error("网络错误，请稍后重试");
					})
					.then(function () {
					    this.bindWxLoading = false;
					});
				},
				useShowDoc:function(){
					this.wxpushLoading = true;
					this.$http.post(
						"/sxdk/api.php?act=useShowDoc",
						{
							form:this.form,
						},
						{ emulateJSON: true }
					)
					.then(function (data) {
						if (data.data.code == 0) {
						    this.resetForm()
					        this.formOther.wxpush_img=""
							vm.$message.success(data.data.msg);
							this.wxpushDialog=false
						} else {
							vm.$message.error(data.data.msg);
						}
					})
					.catch(function () {
						vm.$message.error("网络错误，请稍后重试");
					})
					.then(function () {
					    this.wxpushLoading = false;
					});
				},
				getAsyncTask:function(item){
				    this.actionLoading = true;
				    this.$http.post(
						"/sxdk/api.php?act=getAsyncTask",
						{
							...item
						},
						{ emulateJSON: true }
					)
						.then(function (data) {
							if (data.data.code == 0) {
								let htmlText=data.data.data.filter((item) => {
                    				return item.taskName != "";
                    			}).map((item => {
                    				let taskData=JSON.parse(item.taskData);
                    				return `<li style="font-size: 12px; border-bottom: 1px solid #ddd; padding: 5px 2px;text-align:left">类型：${taskData.levelName}<br/>日期范围：${taskData.startTime}至${taskData.endTime}<br/>状态：${item.code==1?'待执行':item.code==2?'执行中':'已关闭'}<br/>下单时间：${item.createTime}<br/>结束时间：${item.endTime}<br/>更新时间：${item.updateTime}<br/>运行日志：${item.taskMsg}</li>`;
                    			})).join("");
								this.$alert(htmlText, `${item.phone}的补卡/报告记录`, {
									dangerouslyUseHTMLString: true
								});
							} else {
								vm.$message.error(data.data.msg);
							}
						})
						.catch(function () {
							vm.$message.error("网络错误，请稍后重试");
						})
						.then(function () {
							this.actionLoading = false;
						});
				},
				changeCheckCode: function (item) {
					this.$confirm(`账号：${item.phone}，是否要${item.code != 1 ? '启动' : '暂停'}订单？`, `${item.code != 1 ? '启动' : '暂停'}订单`, {
						distinguishCancelAndClose: true,
						confirmButtonText: `${item.code != 1 ? '启动' : '暂停'}`,
						cancelButtonText: '取消'
					})
						.then(() => {
							const form = JSON.parse(JSON.stringify(item));
							if (form["code"] == 1) {
								form["code"] = 2;
							} else {
								form["code"] = 1;
							}
							this.actionLoading = true;
							this.$http.post(
								"/sxdk/api.php?act=changeCheckCode",
								{
									...form
								},
								{ emulateJSON: true }
							)
								.then(function (data) {
									this.query();
									if (data.data.code == 0) {
										vm.$message.success(data.data.msg);
									} else {
										vm.$message.error(data.data.msg);
									}
								})
								.catch(function () {
									vm.$message.error("网络错误，请稍后重试");
								})
								.then(function () {
									this.actionLoading = false;
								});
						})
						.catch(action => { });
				},
				getWxPush: function (item) {
					this.getWxPushLoading = true;
					this.formOther.wxpush_img = "";
					this.$http.post(
						"/sxdk/api.php?act=getWxPush",
						{
							...item
						},
						{ emulateJSON: true }
					)
						.then(function (data) {
							if (data.data.code == 0) {
							    this.formOther.wxpush_img=data.data.data.url
							} else {
								vm.$message.error(data.data.msg);
							}
						})
						.catch(function () {
							vm.$message.error("网络错误，请稍后重试");
						})
						.then(function () {
							this.getWxPushLoading = false;
						});
				},
				async querySourceOrder(item) {
					this.actionLoading = true;
					try {
						let res = await this.$http.post(
							"/sxdk/api.php?act=querySourceOrder",
							{
								form: item,
							},
							{ emulateJSON: true }
						);
						if (res.data.code == 0) {
							return res.data.data;
						} else {
							vm.$message.error(res.data.msg);
							return false;
						}
					} catch (e) {
						vm.$message.error("网络错误，请稍后重试");
						return false;
					} finally {
						this.actionLoading = false;
					}
				},
				update() {
					this.updateLoading = true
					this.$http.post(
						"/sxdk/api.php?act=yunOrder",
						{},
						{ emulateJSON: true }
					).then((res) => {
						if (res.data.code == 0) {
							vm.$message.success(res.data.msg);
							this.query()
						} else {
							vm.$message.error(res.data.msg);
						}
					}).catch(() => {
						vm.$message.error("网络错误，请稍后重试");
					}).then(() => {
						this.updateLoading = false
					})

				},
				getNotice() {
					this.$http.post(
						"/sxdk/api.php?act=getNotice",
						{},
						{ emulateJSON: true }
					)
						.then(function (data) {
							if (data.data.code == 0) {
								this.noticeList = data.data.data
								this.showNotice()
							}
						});
				},
				getCommentList(item) {
				    this.getCommentListLoading=true;
					this.$http.post(
						"/sxdk/api.php?act=getCommentList",
						{form:this.addCommentForm},
						{ emulateJSON: true }
					)
						.then(function (data) {
							if (data.data.code == 0) {
								this.commentList = data.data.data || [];
							} else {
								this.commentList = [];
							}
						})
						.catch(function () {
							vm.$message.error("网络错误，请稍后重试");
						})
						.then(function () {
						    this.getCommentListLoading=false;
						});
				},
				submitaddComment() {
				    this.submitaddCommentLoading=true;
					this.$http.post(
						"/sxdk/api.php?act=addComment",
						{form:this.addCommentForm},
						{ emulateJSON: true }
					)
						.then(function (data) {
							if (data.data.code == 0) {
    							vm.$message.success(data.data.msg);
    							this.addCommentForm.question="";
    						} else {
    							vm.$message.error(data.data.msg);
    						}
    						this.getCommentList(this.addCommentForm)
						})
						.catch(function () {
							vm.$message.error("网络错误，请稍后重试");
						})
						.then(function () {
						    this.submitaddCommentLoading=false;
						});
				},
				showNotice() {
					if (this.noticeList.length <= 0) {
						return;
					}
					let item = this.noticeList.shift();

					this.$alert(item["content"], "公告", {
						dangerouslyUseHTMLString: true,
						callback: action => {
							if (this.noticeList.length > 0) {
								this.showNotice()
							}

						}
					});

				},
				xxyGetSchoolList() {
					//this.xxySchoolLoading = true;
					this.$http.post(
						"/sxdk/api.php?act=xxyGetSchoolList",
						{},
						{ emulateJSON: true }
					)
						.then(function (data) {
							if (data.data.code == 20000) {
							    this.xxySchoolList=[]
								data.data.data.forEach((par) => {
									par.schools.forEach((item) => {
									    let al=this.xxySchoolList.filter((alItem)=>{
									        return alItem.value==item.school_id
									    })
									    if (al.length>0){
									        item.school_id=item.school_id+" "
									    }
										this.xxySchoolList.push({
											text: item.school_name,
											value: item.school_id,
											url: item.differ_api,
										});
									});
								});
								
							} else {
								vm.$message.error("学校列表加载失败，请向上级反馈");
							}
						})
						.catch(function () {
							vm.$message.error("网络错误，请稍后重试");
						})
						.then(function () {
							this.xxySchoolLoading = false;
						});
				},

				xxtGetSchoolList(value) {
					this.xxtgetSchoolLoading = true
					this.$http.post(
						"/sxdk/api.php?act=xxtGetSchoolList",
						{
							filter: value
						},
						{ emulateJSON: true }
					)
						.then(function ({ data }) {
							if (data.result) {
								this.xxtSchoolList = data.froms.map((item) => {
									return {
										text: item.name,
										value: item.schoolid,
									};
								});
							} else {
								this.xxtSchoolList = []
								vm.$message.error("请输入正确的学校名称");
							}
						})
						.catch(function () {
							vm.$message.error("网络错误，请稍后重试");
						})
						.then(function () {
							this.xxtgetSchoolLoading = false
						});
				},
				hzjGetSchoolList() {
					this.hzjSchoolLoading = true;
					this.$http.post(
						"/sxdk/api.php?act=hzjGetSchoolList",
						{},
						{ emulateJSON: true }
					)
						.then(function (data) {
							if (data.data.res == "success") {
								let obj = data.data.data
								this.hzjSchoolList=[]
								for (let key in obj) {
									obj[key].forEach((item) => {
										this.hzjSchoolList.push({
											text: item.schoolName,
											value: item.schoolId,
										});
									});
								}
							} else {
								vm.$message.error("学校列表加载失败，请向上级反馈");
							}
						})
						.catch(function () {
							vm.$message.error("网络错误，请稍后重试");
						})
						.then(function () {
							this.hzjSchoolLoading = false;
						});
				},
				xxyAddressSearchPoi(query) {
					if (query) {
						this.xxyRemote.loading = true
						this.$http
							.post(
								"/sxdk/api.php?act=xxyAddressSearchPoi",
								{
									form: {
										text: query,
										lat: this.form.lat,
										lng: this.form.lng,
									}
								},
								{ emulateJSON: true }
							)
							.then((res) => {
								this.xxyRemote.loading = false
								if (res.data.code == 0) {
									this.xxyAddressPois = res.data.data.addressPois.map((item) => {
										return {
											...item,
											textValue: item.value + item.text,
										};
									});
								} else {
									this.$message.error(res.data.msg);
									this.xxyRemote.loading = false
									this.xxyAddressPois = []
								}
							}).catch((e) => {
								this.$message.error(res.data.msg);
								this.xxyRemote.loading = false
								this.xxyAddressPois = []
							});
					} else {
						this.xxyAddressPois = []
					}
				},
				handleResize() {
					window.addEventListener('resize', this.onResize);
				},
				onResize() {
					const mobile = window.innerWidth <= 675;
					this.isMobile = mobile;
					if (mobile) {
						this.addeditDialogWidth = "100%"
						this.allSize = "mini"
						this.batchDialogWidth = "100%"
						this.batchLogMaxHeight = Math.max(220, window.innerHeight - 260)
					} else {
						this.addeditDialogWidth = "675px"
						this.allSize = "small"
						this.batchDialogWidth = "720px"
						this.batchLogMaxHeight = 360
					}
					if (window.innerHeight <= 600) {
						this.addeditDialogHeight = "100%"
						if (mobile) {
							this.batchLogMaxHeight = Math.max(180, window.innerHeight - 220)
						}
					} else {
						this.addeditDialogHeight = "500px"
					}
				},
				tableRowClassName({ row, rowIndex }) {
					if (row.code == 1) {
						return 'success-row';
					} else {
						return 'warning-row';
					}
				},
			},
			beforeDestroy() {
				window.removeEventListener('resize', this.onResize);
			},

		});
	</script>
	<style>
		.el-table .warning-row {
			background: oldlace;
		}

		.el-table .success-row {
			background: white;
		}

		.el-message-box {
			width: 320px;

		}

		.el-message-box .el-message-box__content {
			max-height: 600px;
			overflow: auto;
		}

		.addeditDialog .el-dialog {
			margin-top: 5vh !important;
		}

		.addeditDialog .el-dialog__body {
			padding: 30px 2vw;
		}

		.card-header {
			margin-top: 20px;
			margin-bottom: 10px;
		}

		.card-body {
			margin-top: 10px;
			margin-bottom: 20px;
		}

		.el-form-item {
			margin-bottom: 15px;
		}

		.el-form-item__label {
			line-height: 30px;
		}

		.el-form-item__content {
			line-height: 30px;
		}

		.el-form-item__error {
			padding-top: 0;
		}

		.el-dialog__body {
			height: 65vh;
			overflow: auto;
		}

		.batchDialog .el-dialog__body {
			height: auto;
			max-height: 70vh;
			overflow: auto;
			padding: 15px 20px;
		}

		.batchDialog--mobile .el-dialog__body {
			max-height: none;
			height: calc(100vh - 108px);
			padding: 12px 14px;
			display: flex;
			flex-direction: column;
			box-sizing: border-box;
			overflow-x: hidden;
		}

		.batchDialog--mobile .batch-progress-wrap {
			flex-shrink: 0;
		}

		.batchDialog--mobile .batch-log-title {
			flex-shrink: 0;
		}

		.batchDialog--mobile .batch-log-mobile {
			flex: 1;
			min-height: 0;
			max-height: none !important;
		}

		.batch-progress-wrap {
			margin-bottom: 15px;
		}

		.batch-progress-info {
			display: flex;
			flex-wrap: wrap;
			gap: 8px 16px;
			margin-bottom: 8px;
			font-size: 13px;
			color: #606266;
		}

		.batch-stat-success {
			color: #67C23A;
			font-style: normal;
			font-weight: 600;
		}

		.batch-stat-fail {
			color: #F56C6C;
			font-style: normal;
			font-weight: 600;
		}

		.batch-log-title {
			font-size: 14px;
			font-weight: bold;
			margin-bottom: 8px;
			color: #303133;
		}

		.batch-log-mobile {
			overflow-y: auto;
			overflow-x: hidden;
			width: 100%;
			max-width: 100%;
			-webkit-overflow-scrolling: touch;
		}

		.batch-log-mobile-item {
			border: 1px solid #ebeef5;
			border-radius: 6px;
			padding: 10px 12px;
			margin-bottom: 8px;
			background: #fff;
			font-size: 12px;
			max-width: 100%;
			box-sizing: border-box;
			overflow: hidden;
		}

		.batch-log-mobile-item.is-success {
			border-left: 3px solid #67C23A;
		}

		.batch-log-mobile-item.is-fail {
			border-left: 3px solid #F56C6C;
		}

		.batch-log-mobile-head {
			display: flex;
			align-items: center;
			gap: 8px;
			margin-bottom: 6px;
		}

		.batch-log-mobile-index {
			font-weight: 600;
			color: #303133;
		}

		.batch-log-mobile-time {
			margin-left: auto;
			color: #909399;
			font-size: 11px;
		}

		.batch-log-mobile-row {
			color: #606266;
			line-height: 1.6;
			display: flex;
			gap: 6px;
			align-items: flex-start;
		}

		.batch-log-mobile-row .label {
			display: inline-block;
			width: 36px;
			flex-shrink: 0;
			color: #909399;
		}

		.batch-log-mobile-value {
			flex: 1;
			min-width: 0;
			word-break: break-word;
			overflow-wrap: anywhere;
		}

		.batch-log-mobile-msg {
			margin-top: 6px;
			padding-top: 6px;
			border-top: 1px dashed #ebeef5;
			color: #303133;
		}

		.batch-log-mobile-msg-text {
			line-height: 1.5;
			white-space: pre-wrap;
		}

		.batch-log-empty {
			text-align: center;
			color: #909399;
			padding: 24px 0;
			font-size: 13px;
		}

		.batch-dialog-footer .el-button {
			width: 100%;
		}

		.batchDialog--mobile .batch-dialog-footer {
			padding: 8px 14px;
		}

		.page-toolbar {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			justify-content: space-between;
			gap: 10px;
			margin: 10px 0 12px;
		}

		.page-toolbar-actions {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: 8px;
		}

		.page-toolbar-search {
			flex: 1 1 220px;
			min-width: 0;
			max-width: 420px;
		}

		.page-toolbar .batch-bar {
			flex-basis: 100%;
			width: 100%;
		}

		.page-toolbar-extra {
			font-size: 12px;
			color: #909399;
		}

		.batch-bar {
			display: flex;
			flex-wrap: wrap;
			align-items: center;
			gap: 8px;
			margin-bottom: 0;
			padding: 10px 12px;
			background: #fdf6ec;
			border-radius: 4px;
			border: 1px solid #faecd8;
		}

		.batch-selected {
			font-size: 13px;
			color: #E6A23C;
			font-weight: 500;
			white-space: nowrap;
		}

		.batch-select {
			flex: 1 1 160px;
			min-width: 120px;
		}

		.addeditDialog--mobile .el-dialog__body {
			height: auto;
			max-height: none;
			padding: 12px 14px;
		}

		.addeditDialog--mobile .form-hint,
		.addeditDialog--mobile .el-form-item .el-form-item__content > span[style*="margin-left"] {
			display: block;
			margin-left: 0 !important;
			margin-top: 4px;
			font-size: 12px;
			line-height: 1.4;
		}

		.addeditDialog--mobile .el-select,
		.addeditDialog--mobile .el-date-editor,
		.addeditDialog--mobile .el-time-picker {
			width: 100% !important;
		}

		.bu-papers-radio-group {
			display: flex;
			flex-wrap: wrap;
			gap: 8px 12px;
		}

		.mobile-dialog-footer {
			display: flex;
			gap: 10px;
		}

		.wxpush-content {
			text-align: center;
		}

		.wxpush-qrcode {
			width: 250px;
			height: 250px;
			max-width: 100%;
		}

		.wxpush-input-row {
			text-align: left;
			margin-top: 12px;
			font-size: 12px;
		}

		.order-mobile-list {
			min-height: 120px;
		}

		.order-mobile-empty {
			text-align: center;
			color: #909399;
			padding: 40px 16px;
			font-size: 13px;
		}

		.order-mobile-item {
			border: 1px solid #ebeef5;
			border-radius: 8px;
			padding: 12px;
			margin-bottom: 10px;
			background: #fff;
		}

		.order-mobile-item.is-running {
			border-left: 3px solid #409EFF;
		}

		.order-mobile-item.is-selected {
			background: #ecf5ff;
			border-color: #b3d8ff;
		}

		.order-mobile-head {
			display: flex;
			align-items: center;
			gap: 8px;
			margin-bottom: 8px;
		}

		.mobile-select-btn {
			width: 22px;
			height: 22px;
			border: 1px solid #dcdfe6;
			border-radius: 4px;
			display: flex;
			align-items: center;
			justify-content: center;
			flex-shrink: 0;
			color: transparent;
			cursor: pointer;
		}

		.mobile-select-btn.active {
			background: #409EFF;
			border-color: #409EFF;
			color: #fff;
		}

		.order-mobile-title {
			flex: 1;
			min-width: 0;
			display: flex;
			align-items: center;
			gap: 6px;
		}

		.order-mobile-phone {
			font-weight: 600;
			font-size: 14px;
			word-break: break-all;
		}

		.order-mobile-meta {
			font-size: 12px;
			color: #606266;
			margin-bottom: 6px;
		}

		.order-mobile-row {
			font-size: 12px;
			color: #606266;
			line-height: 1.6;
			display: flex;
			gap: 6px;
		}

		.order-mobile-row .label {
			color: #909399;
			flex-shrink: 0;
			width: 32px;
		}

		.order-mobile-address {
			word-break: break-all;
		}

		.order-mobile-tags {
			display: flex;
			flex-wrap: wrap;
			gap: 4px;
			margin: 8px 0;
		}

		.order-mobile-actions {
			display: flex;
			gap: 6px;
			margin-top: 8px;
		}

		.order-mobile-actions .el-button {
			flex: 1;
			padding-left: 0;
			padding-right: 0;
		}

		.end-time-warning {
			color: #E6A23C;
			font-weight: 600;
		}

		.end-time-expired {
			color: #F56C6C;
			font-weight: 600;
		}

		.mobile-pagination {
			margin-top: 12px;
			text-align: center;
		}

		.mobile-action-list {
			display: flex;
			flex-direction: column;
			gap: 8px;
			padding: 0 16px 20px;
		}

		.mobile-action-list .el-button {
			width: 100%;
			margin-left: 0;
		}

		.mobile-action-drawer .el-drawer__body {
			padding-bottom: env(safe-area-inset-bottom);
		}

		.comment-mobile-list {
			min-height: 80px;
		}

		.comment-mobile-item {
			border: 1px solid #ebeef5;
			border-radius: 6px;
			padding: 10px;
			margin-bottom: 8px;
		}

		.comment-mobile-head {
			display: flex;
			align-items: center;
			justify-content: space-between;
			margin-bottom: 6px;
		}

		.comment-mobile-time {
			font-size: 11px;
			color: #909399;
		}

		.comment-mobile-question {
			font-size: 13px;
			color: #303133;
			line-height: 1.5;
			word-break: break-all;
		}

		.page-toolbar-search--mobile {
			display: flex;
			flex-direction: column;
			gap: 8px;
		}

		.mobile-search-type {
			width: 100%;
		}

		@media (max-width: 675px) {
			#content.lyear-layout-content {
				padding-top: 8px !important;
				padding-left: 0 !important;
			}

			.box-card .el-card__body {
				padding: 12px;
			}

			.page-toolbar,
			.page-toolbar--mobile {
				flex-direction: column;
				align-items: stretch;
				gap: 8px;
				margin: 8px 0 6px;
			}

			.page-toolbar-search {
				max-width: none;
				width: 100%;
				flex: none;
				order: 2;
			}

			.page-toolbar-actions {
				display: grid;
				grid-template-columns: repeat(2, 1fr);
				gap: 8px;
				width: 100%;
				order: 1;
			}

			.page-toolbar .batch-bar {
				order: 3;
				margin-top: 0;
			}

			.page-toolbar-actions .el-button {
				width: 100%;
				margin: 0 !important;
				min-height: 36px;
				padding: 8px 4px;
				font-size: 13px;
			}

			.page-toolbar-extra {
				grid-column: 1 / -1;
				font-size: 11px;
				line-height: 1.3;
				word-break: break-all;
			}

			.page-toolbar-search--mobile {
				gap: 6px;
			}

			.batch-bar {
				padding: 8px 10px;
				gap: 6px;
			}

			.batch-select {
				width: 100%;
			}

			.batch-bar .el-button {
				width: 100%;
			}

			.card-body {
				margin-top: 0;
			}

			.box-card .el-alert {
				padding: 8px 12px;
			}

			.box-card .el-alert__title {
				font-size: 12px;
				line-height: 1.4;
			}

			.el-message-box {
				width: 92vw !important;
				max-width: 480px;
			}

			.el-message {
				min-width: 0 !important;
				width: calc(100vw - 32px) !important;
				max-width: calc(100vw - 32px);
				box-sizing: border-box;
			}

			.el-message__content {
				word-break: break-word;
				overflow-wrap: anywhere;
				white-space: pre-wrap;
				line-height: 1.4;
			}

			.mobile-dialog-footer {
				flex-direction: column;
			}

			.mobile-dialog-footer .el-button {
				width: 100%;
				margin-left: 0 !important;
			}

			.addeditDialog--mobile.el-dialog.is-fullscreen .el-dialog__body {
				height: calc(100vh - 110px);
				overflow-y: auto;
			}
		}
	</style>
</div>