<?php

require_once DISCUZ_ROOT . './extend/vendor/storage/qcloud/vendor/autoload.php';
require_once DISCUZ_ROOT . './extend/vendor/storage/tencentcloud_sdk/vendor/autoload.php';

use GuzzleHttp\Client;
use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Common\Exception\TencentCloudSDKException;
use TencentCloud\Ms\V20180408\MsClient;
use TencentCloud\Ms\V20180408\Models\DescribeUserBaseInfoInstanceRequest;

class QcloudBass
{

    //开启数据上报标志
    const SITE_REPORT_OPEN = '1';
    //开启自定义密钥标志
    const SITE_SECKEY_OPEN = '1';
    private $secret_id;
    private $secret_key;
    private $region;
    private $bucket;
    private $debug_mode;
    private $site_id = '';
    private $site_url = '';
    private $site_app = 'Discuz! X';
    private $action = 'save_config';
    private $plugin_type = 'cos';
    private $upload_url = 'https://openapp.qq.com/api/public/index.php/upload';

    public function __construct($secret_id, $secret_key, $region, $bucket)
    {
        $this->secret_id = $secret_id;
        $this->secret_key = $secret_key;
        $this->region = $region;
        $this->bucket = $bucket;
    }

    /**
     * 返回cos对象
     * @param array $options 用户自定义插件参数
     * @return \Qcloud\Cos\Client
     */
    public function getCosClient($secret_id, $secret_key, $region)
    {
        if (empty($region) || empty($secret_id) || empty($secret_key)) {
            return false;
        }

        return new Qcloud\Cos\Client(
            array(
                'region' => $region,
                'schema' => ($this->isHttps() === true) ? "https" : "http",
                'credentials' => array(
                    'secretId' => $secret_id,
                    'secretKey' => $secret_key
                )
            )
        );
    }

    /**
     * 判断是否为https请求
     * @return bool
     */
    public function isHttps()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            return true;
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        } elseif (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off') {
            return true;
        } elseif ($_SERVER['SERVER_PORT'] == 443) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @param $target
     * @param $fh
     * @param bool $flag
     * @return bool|string
     */
    public function uploadFileToCos($source, $target)
    {
        try {
            $cosClient = $this->getCosClient($this->secret_id, $this->secret_key, $this->region);
            $fh = fopen($source, 'rb');
            if ($fh) {
                $result = $cosClient->Upload(
                    $bucket = $this->bucket,
                    $key = $target,
                    $body = $fh
                );
                fclose($fh);
                return true;
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    public function set_debug_mode($debug_mode = true){
        $this->debug_mode = $debug_mode;
    }

    /**
     * @param $key
     * @return bool
     */
    public function deleteRemoteAttachment($key)
    {
        $deleteObjects[] = array(
            'Key' => $key
        );
        if (!empty($deleteObjects)) {
            $cosClient = $this->getCosClient($this->secret_id, $this->secret_key, $this->region);
            try {
                $result = $cosClient->deleteObjects(array(
                    'Bucket' => $this->bucket,
                    'Objects' => $deleteObjects,
                ));
                return true;
            } catch (Exception $ex) {
                return false;
            }
        }
    }

    /**
     * 保存公共密钥时发送用户使用插件的信息，不含隐私信息
     */
    public function sendUserExperienceInfo() {
        $url = $this->upload_url;
        $static_data = array(
            'action' => $this->action,
            'plugin_type' => $this->plugin_type,
            'data' => array(
                'site_id'  => $this->getDiscuzSiteID(),
                'site_url' => $this->getDiscuzSiteUrl(),
                'site_app' => $this->getDiscuzSiteApp()
            )
        );

        if (!empty($this->secret_id) && !empty($this->secret_key)) {
            $static_data['data']['uin'] = $this->getUserUinBySecret($this->secret_id, $this->secret_key);
        }

        if (empty($static_data['data']['uin'])) {
            die;
        }

        $others = array(
            'cos_region' => $this->region,
            'cos_bucket' => $this->bucket,
        );
        $static_data['data']['others'] = json_encode($others);
        $static_data['data']['cust_sec_on'] = (int)self::SITE_SECKEY_OPEN;
        $this->sendPostRequest($url, $static_data);
    }

    /**
     * 获取唯一站点ID
     */
    public function getDiscuzSiteID(){
        global $_G;
        if ($_G['setting']['tencentcloud_center']){
            $params = unserialize($_G['setting']['tencentcloud_center']);
            return $params['site_id'];
        } else {
            $data = array (
                'secretid' => '',
                'secretkey' => '',
                'site_sec_on' => self::SITE_SECKEY_OPEN,
                'site_report_on' => self::SITE_REPORT_OPEN,
                'site_id'=>uniqid('discuzx_'),
            );
            C::t('common_setting')->update_batch(array("tencentcloud_center" => $data));
            //更新缓存信息
            updatecache('setting');
            return $data['site_id'];
        }
    }

    public function getDiscuzSiteApp()
    {
        return $this->site_app;
    }

    public function getDiscuzSiteUrl()
    {
        if (empty($this->site_url)) {
            global $_G;
            $this->site_url = rtrim($_G['siteurl'], '/');
        }
        return $this->site_url;

    }
    /**
     * 发送post请求
     * @param $url
     * @param $data
     */
    public function sendPostRequest($url, $data)
    {
        ob_start();
        if (function_exists('curl_init')) {
            $json_data = json_encode($data);
            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json"));
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $json_data);
            curl_exec($curl);
            curl_close($curl);
        } else {
            $client = new Client();
            $client->post($url, [
                GuzzleHttp\RequestOptions::JSON => $data
            ]);
        }
        ob_end_clean();
    }

    /**
     * 获取用户基础信息 UserUin
     * @param $option string 腾讯云账号的密钥信息 SecretId 和SecretKey
     * @return bool|mixed UserUin的值
     */
    public function getUserUinBySecret($secret_id, $secret_key)
    {
        if ( empty($secret_id) || empty($secret_key)) {
            return '';
        }
        try {
            $cred = new Credential($secret_id, $secret_key);
            $httpProfile = new HttpProfile();
            $httpProfile->setEndpoint("ms.tencentcloudapi.com");
            $clientProfile = new ClientProfile();
            $clientProfile->setHttpProfile($httpProfile);
            $client = new MsClient($cred, "", $clientProfile);
            $req = new DescribeUserBaseInfoInstanceRequest();
            $params = "{}";
            $req->fromJsonString($params);

            $resp = $client->DescribeUserBaseInfoInstance($req);
            if (is_object($resp)) {
                $result = json_decode($resp->toJsonString(), true);
                return isset($result['UserUin']) ? $result['UserUin'] : '';
            } else {
                return '';
            }
        } catch (TencentCloudSDKException $e) {
            echo '';
        }
    }


    /**
     * 检查存储桶是否存在
     * @param $tcwpcos_options
     * @return bool
     */
    public function checkCosBucket($setting)
    {
        $cosClient = $this->getCosClient($setting['ftp']['secretid'], $setting['ftp']['secretkey'], $setting['ftp']['region']);
        if (!$cosClient) {
            return false;
        }
        try {
            if ($cosClient->doesBucketExist($setting['ftp']['bucket'])) {
                $this->sendUserExperienceInfo();
                return true;
            }
            return false;
        } catch (ServiceResponseException $e) {
            return false;
        }
    }
}