<?php
/**
 * 企业微信评论通知插件 - 修正时间版本
 * 
 * @package WeComCommentNotify
 * @author 桦哲
 * @version 1.1.0
 * @link https://web.zyhmifan.top/
 */
class WeComCommentNotify_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件
     */
    public static function activate()
    {
        // 设置默认时区（如果服务器未正确配置）
        if (function_exists('date_default_timezone_set') && !ini_get('date.timezone')) {
            date_default_timezone_set('Asia/Shanghai');
        }
        
        Typecho_Plugin::factory('Widget_Feedback')->comment = array(__CLASS__, 'sendWeComNotify');
        Typecho_Plugin::factory('Widget_Feedback')->trackback = array(__CLASS__, 'sendWeComNotify');
        
        return _t('企业微信评论通知插件已激活，时间问题已修正');
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        return _t('企业微信评论通知插件已禁用');
    }

    /**
     * 插件配置面板
     * 
     * @param Typecho_Widget_Helper_Form $form
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // Webhook地址
        $webhook = new Typecho_Widget_Helper_Form_Element_Text(
            'webhook', 
            NULL, 
            NULL, 
            _t('企业微信机器人Webhook地址'), 
            _t('格式：https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=xxxxxxxx')
        );
        $webhook->addRule('required', _t('Webhook地址不能为空'));
        $webhook->addRule('url', _t('请输入有效的URL地址'));
        $form->addInput($webhook);

        // 时区设置
        $timezone = new Typecho_Widget_Helper_Form_Element_Select(
            'timezone',
            array(
                'Asia/Shanghai' => '中国标准时间 (UTC+8)',
                'Asia/Tokyo' => '日本时间 (UTC+9)',
                'America/New_York' => '美国东部时间 (UTC-5)',
                'Europe/London' => '伦敦时间 (UTC+0)'
            ),
            'Asia/Shanghai',
            _t('时区设置'),
            _t('请选择您的服务器所在时区')
        );
        $form->addInput($timezone);

        // @成员设置
        $mentioned_list = new Typecho_Widget_Helper_Form_Element_Text(
            'mentioned_list', 
            NULL, 
            NULL, 
            _t('需要@的成员ID'), 
            _t('多个成员用英文逗号分隔，如：zhangsan,lisi')
        );
        $form->addInput($mentioned_list);

        // @手机号设置
        $mentioned_mobile_list = new Typecho_Widget_Helper_Form_Element_Text(
            'mentioned_mobile_list', 
            NULL, 
            NULL, 
            _t('需要@的手机号'), 
            _t('多个手机号用英文逗号分隔，如：13800138000,13900139000')
        );
        $form->addInput($mentioned_mobile_list);

        // 消息格式选择
        $msg_type = new Typecho_Widget_Helper_Form_Element_Radio(
            'msg_type',
            array(
                'text' => '纯文本',
                'markdown' => 'Markdown格式'
            ),
            'text',
            _t('消息格式'),
            _t('选择发送到企业微信的消息格式')
        );
        $form->addInput($msg_type);
    }

    /**
     * 个人用户的配置面板
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}

    /**
     * 发送企业微信通知
     */
    public static function sendWeComNotify($comment, $post)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $pluginOptions = $options->plugin('WeComCommentNotify');
        
        // 获取配置
        $webhook = $pluginOptions->webhook;
        $timezone = $pluginOptions->timezone;
        $msgType = $pluginOptions->msg_type ?: 'text';
        
        // 处理时间显示
        try {
            $dt = new DateTime('@' . $comment['created']);
            $dt->setTimezone(new DateTimeZone($timezone));
            $formattedTime = $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $formattedTime = date('Y-m-d H:i:s', $comment['created']);
        }

        // 构建@信息
        $at = array();
        if (!empty($pluginOptions->mentioned_list)) {
            $at['mentioned_list'] = array_map('trim', 
                explode(',', str_replace('，', ',', $pluginOptions->mentioned_list)));
        }
        if (!empty($pluginOptions->mentioned_mobile_list)) {
            $at['mentioned_mobile_list'] = array_map('trim',
                explode(',', str_replace('，', ',', $pluginOptions->mentioned_mobile_list)));
        }

        // 根据消息类型构建不同内容
        if ($msgType === 'markdown') {
            $data = array(
                'msgtype' => 'markdown',
                'markdown' => array(
                    'content' => "### 博客有新评论\n\n" .
                                 "**评论人**: {$comment['author']}\n\n" .
                                 "**评论文章**: [{$post->title}]({$post->permalink})\n\n" .
                                 "**评论内容**: \n> {$comment['text']}\n\n" .
                                 "**评论时间**: {$formattedTime}"
                ),
                'at' => $at
            );
        } else {
            $data = array(
                'msgtype' => 'text',
                'text' => array(
                    'content' => "博客有新评论\n\n" .
                                 "评论人: {$comment['author']}\n\n" .
                                 "评论文章: {$post->title}\n" .
                                 "文章链接: {$post->permalink}\n\n" .
                                 "评论内容: \n{$comment['text']}\n\n" .
                                 "评论时间: {$formattedTime}"
                ),
                'at' => $at
            );
        }

        // 发送请求
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $webhook,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 记录错误日志（可选）
        if ($httpCode != 200) {
            file_put_contents(__DIR__ . '/notify_error.log', 
                date('Y-m-d H:i:s') . " - HTTP {$httpCode} - Response: {$response}\n",
                FILE_APPEND);
        }

        return $comment;
    }
}