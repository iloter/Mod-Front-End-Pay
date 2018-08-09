<?php
/**
 * 本文件基于91Pay的支付宝当面付修改，使用前请导入必要的数据库
 * User: 91yun
 * Date: 9/8/18
 * Time: 12:33 PM
 */
namespace App\Utils;
use App\Services\Config;
use App\Models\User;
use App\Models\Code;
use App\Models\Paylist;
use App\Models\Payback;

class GeekPay{
	public static function getGen($user, $amount,$type)
  {
    	return GeekPay::GeekPay_gen($user, $amount,$type);
	}
	public static function callback($request)
  {
      return GeekPay::paypal_callback($request);
	}
	private static function GetOrderurl($user, $amount,$invocenum,$type){
			$Notice_url = Config::get("baseUrl").'/GeekPay_callback';
			$token = Config::get("GeekPay_Token");
			$tokenapi = Config::get("GeekPay_TokenKey");
			$reurl=Config::get('baseUrl').'/user/code';
			$remark = $user->user_name.'在'.Config::get("appName").'充值'.$amount.'元';
			$time = time();
			$tokencrypt = md5($time.$amount.$tokenapi);
			$params = [
					'type' => $type,
					'Fee' => $amount,
					'Orderid' => $invocenum,
					'Remark' => $remark,
					'token'=> $token,
					'tokencrypt' => $tokencrypt,
					'time' => $time,
					'Return_Url'=>$reurl,
					'Cancel_Url'=>$reurl,
					'Notice_Url' => $Notice_url
			];
			$params = http_build_query($params);
			$api = 'https://pay.4gml.com/PayApi?'.$params;
			return $api;
	}
	private static function GeekPay_gen($user, $amount,$type)
  {
			$pl = new Paylist();
	    $pl->userid = $user->id;
	    $pl->total = $amount;
	    $pl->save();
			$ddid=$pl->id;
			$GeekPayurl = GeekPay::GetOrderurl($user, $amount, $ddid,$type);
			header('Location:'.$GeekPayurl);
			exit();
			return ;
  }

	public static function getHTML($user)
  {
			$params = [
					'check' => 'true',
					'Token'=> Config::get("GeekPay_Token"),
					'Tokenkey' => md5(Config::get("GeekPay_TokenKey"))
			];
			$params = http_build_query($params);
			$api = 'https://pay.4gml.com/PayApi?'.$params;
			$res = json_decode(file_get_contents($api),true);

			if(isset($res)){
				if($res['status'] == 1 && ($res['qq']!=0 || $res['zfb']!=0 || $res['wx']!=0)){
					if(Config::get("GeekPay_State")!=false){
						$qq =  $res['qq']!=0?'<option value="qq">QQ支付▾</option>':null;
						$wx =  $res['wx']!=0?'<option value="wx">微信支付▾</option>':null;
						$zfb =  $res['zfb']!=0?'<option value="zfb">支付宝支付▾</option>':null;
						return '
						<div class="">
							<div class="block">
								<div class="block-header">
									<h3 class="card-heading"><i class="icon icon-lg">monetization_on</i>使用微信/QQ充值</h3>
								</div>
								<div class="block-content">
									<form class="form-horizontal" action="/user/GeekPay" method="get" target="_blank">
										<div class="form-group">
											<select name="Paymethod" id="Paymethod" class="form-control">'.$qq.$wx.$zfb.'	</select>
											<div class="">
												<div class="input-group">
												<input class="form-control" type="text" id="Geekpay" name="amount" placeholder="请输入你要充值的金额">
													<span class="input-group-addon"><i class="si si-badge fa"></i></span>
												</div>
											</div>
										</div>
											<div class="form-group  text-right">
												<div class="">
												<button class="btn btn-flat waves-attach waves-effect" type="submit"><span class="icon">check</span> 充值</button>
												</div>
											</div>
										</form>
									</div>
								</div>
							</div>';
				}else{
					return null;
				}
			}else{
				return null;
			}
		}else{
			return null;
		}
	}

	public static function GeekPay_callback()
  {
		if(!isset($_GET['Result'], $_GET['Notice_Key'], $_GET['Orderid'])){//如果返回值为空则返回充值中心
			$reurl = Config::get('baseUrl');
			header('Location:'.$reurl.'/user/code');
			exit();
		}else if($_GET['Notice_Key']!=md5(Config::get('GeekPay_Notice_check'))){//如果通知Key错误，返回用户中心
			$reurl = Config::get('baseUrl');
			header('Location:'.$reurl.'/user/code');
			exit();
		}else{
			//取消支付用户跳转到充值中心
			if($_GET['Result'] == 'Pay_Fail'){
				$out_trade_no = $_GET['Orderid'];
				$trade_no = $_GET['Trans_id'];
				$trade = Paylist::where("id", '=', $out_trade_no)->where('status', 0)->where('total', $_GET['Fee'])->first();
				if($trade)
					$trade->delete();
				exit();
			}
			$out_trade_no = $_GET['Orderid'];
			$trade_no = $_GET['Trans_id'];
			$trade = Paylist::where("id", '=', $out_trade_no)->where('status', 0)->where('total', $_GET['Fee'])->first();
      if ($trade == null||$trade_no==null) {//没有符合的订单，或订单已经处理，或订单号为空则判断为未支付
        $reurl=Config::get('baseUrl');
				header('Location:'.$reurl.'/user/code');
				exit();
      }
				$trade->tradeno = $trade_no;
				$trade->status = 1;
				$trade->save();
				$user=User::find($trade->userid);
				$czmoney=$_GET['Fee'];
      	$user->money=$user->money+$czmoney;
				if ($user->class==0) {
          $user->class_expire=date("Y-m-d H:i:s", time());
          $user->class_expire=date("Y-m-d H:i:s", strtotime($user->class_expire)+86400);
					$user->class=1;
				}
        $user->save();
        $codeq=new Code();
        $codeq->code="GeekPay充值";
        $codeq->isused=1;
        $codeq->type=-1;
        $codeq->number=$czmoney;
        $codeq->usedatetime=date("Y-m-d H:i:s");
        $codeq->userid=$user->id;
        $codeq->save();
				if ($user->ref_by!=""&&$user->ref_by!=0&&$user->ref_by!=null) {
					$gift_user=User::where("id", "=", $user->ref_by)->first();
					$gift_user->money=$gift_user->money+($codeq->number*0.2);
					$gift_user->save();
					$Payback=new Payback();
					$Payback->total=$czmoney;
					$Payback->userid=$user->id;
					$Payback->ref_by=$user->ref_by;
					$Payback->ref_get=$codeq->number*0.2;
					$Payback->datetime=time();
					$Payback->save();
				}
				$reurl = Config::get('baseUrl');
				header('Location:'.$reurl.'/user/code');
				exit();
		}
  }
}
?>
