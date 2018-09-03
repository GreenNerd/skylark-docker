<?php
namespace jssdk;
use think\Session;

class JSSDK {

    private $appId;

    private $appSecret;

    public function __construct($appId, $appSecret) {

        $this->appId = $appId;

        $this->appSecret = $appSecret;

    }

    public function getSignPackage() {

        $jsapiTicket = $this->getJsApiTicket();

        $url = "http://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
//        var_dump($url);exit;
        $timestamp = time();

        $nonceStr = $this->createNonceStr();

        // 这里参数的顺序要按照 key 值 ASCII 码升序排序

        $string = "jsapi_ticket=$jsapiTicket&noncestr=$nonceStr&timestamp=$timestamp&url=$url";

        $signature = sha1($string);

        $signPackage = array(
            "appId"     => $this->appId,
            "nonceStr"  => $nonceStr,
            "timestamp" => $timestamp,
            "url"       => $url,
            "signature" => $signature,
            "rawString" => $string
        );

        return $signPackage;

    }

    private function createNonceStr($length = 16) {

        $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";

        $str = "";

        for ($i = 0; $i < $length; $i++) {

            $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);

        }

        return $str;

    }

    private function getJsApiTicket() {
        // jsapi_ticket 应该全局存储与更新，以下代码以写入到文件中做示例
        $ticket = Session::get('ticket');
        $expired_at =Session::get('expired_at');
        if (!empty($ticket)&&(time()>$expired_at)){
            return $ticket;
        }else{
            $url = getenv('GER_URL').'/api/v4/wechat_clients/jsapi_ticket';
            $options = array(
                'http' => array(
                    'method' => 'GET',
                    'header' => 'Authorization:'.getenv('INTERFACE_SIGNATURE'),
                )
            );
            $context = stream_context_create($options);

            $result = file_get_contents($url,false,$context);

            $results = json_decode($result,true);

            $ticket = $results['ticket'];
            Session::set('ticket',$ticket);
            $time = $results['expired_at']-1;
            Session::set('expired_at',$time);
            return $ticket;
        }
    }


    private function httpGet($url) {

        $curl = curl_init();

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        curl_setopt($curl, CURLOPT_TIMEOUT, 500);

        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);

        curl_setopt($curl, CURLOPT_URL, $url);

        $res = curl_exec($curl);

        curl_close($curl);

        return $res;

    }

}
