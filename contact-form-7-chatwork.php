<?php
/*
Plugin Name: Contact Form 7 with ChatWork
Plugin URI:
Description: ChatWork にフォームの内容を送信します。
Author: IMPATH Inc.
Version: 1.1.0
Author URI: https://impath.co.jp
*/

add_action( 'wpcf7_mail_sent', 'impath_send_cw_message');
function impath_send_cw_message( $cf7 ){

    if(!get_option('impath_cw_api_token') || !get_option('impath_cw_roomid'))return;

    if(!method_exists($cf7, 'replace_mail_tags')) {
        $mail_properties = $cf7->get_properties();
        $mail = $mail_properties['mail'];
        $mail_subject_template = $mail['subject'];
        $mail_body_template = $mail['body'];
        $mail_subject = wpcf7_mail_replace_tags($mail_subject_template);
        $mail_body = wpcf7_mail_replace_tags($mail_body_template);
    }elseif(method_exists($cf7, 'replace_mail_tags')) {
        $mail_template = $cf7->setup_mail_template( $cf7->mail, 'mail' );
        $mail_subject = $cf7->replace_mail_tags( $mail_template['subject'] );
        $mail_body = $cf7->replace_mail_tags( $mail_template['body'] );
    }

    $body = "[info][title]".$mail_subject."[/title]".$mail_body."[/info]";
    $roomid = get_option('impath_cw_roomid');
    $key = get_option('impath_cw_api_token');
    $url = 'https://api.chatwork.com/v2/rooms/'.$roomid.'/messages';
    $response = wp_remote_post($url, array(
        'headers' => array(
            'X-ChatWorkToken' => $key,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ),
        'body' => array(
            'body' => $body,
            'self_unread' => 1, // 0:未読にしない, 1:未読にする
        ),
    ));

    if (is_wp_error($response)) {
        error_log('[ChatWork API] リクエスト失敗: ' . $response->get_error_message());
    } else {
        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($status_code === 200 || $status_code === 201) {
            error_log('[ChatWork API] メッセージ送信成功: ステータスコード ' . $status_code . ', レスポンス: ' . $response_body);
        } else {
            error_log('[ChatWork API] メッセージ送信失敗: ステータスコード ' . $status_code . ', レスポンス: ' . $response_body);
        }
    }
}

add_action('admin_menu', 'impath_cf7cw_admin_menu');
function impath_cf7cw_admin_menu(){
    add_options_page('ChatWork連携設定', 'ChatWork連携設定', 8, __FILE__, 'impath_cf7cw_admin_opt_page');
}

function impath_cf7cw_admin_opt_page(){
    ?>
    <div class="wrap">
        <div id="icon-options-general" class="icon32">
            <br>
        </div>
        <h2>Contact Form 7 + ChatWork連携オプション</h2>
        <form method="post" action="options.php">
            <?php wp_nonce_field('update-options'); ?>
            <p>Contact Form 7のフォームからメッセージが送られるとChatWorkの指定のチャットルームに送信されます。</p>
            <p style="color:red"><strong>本プラグインを動作させるには必ず <a target="_blank" href="http://wordpress.org/plugins/contact-form-7/">Contact Form 7</a> がインストールされている必要があります。</strong></p>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="api_token">ChatWork APIトークン</label>
                    </th>
                    <td>
                        <input id="api_token" type="text" class="regular-text ltr" name="impath_cw_api_token" value="<?php echo get_option('impath_cw_api_token'); ?>" />
                        <p class="description">本プラグインの動作にはChatWork社のAPIトークンが必要になります。</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">
                        <label for="roomid">投稿先のチャットルームのルームID</label>
                    </th>
                    <td>
                        <input id="roomid" type="text" class="regular-text ltr" name="impath_cw_roomid" value="<?php echo get_option('impath_cw_roomid'); ?>" /><p class="description">ルームIDはChatWorkでルームを選択した際に、ブラウザのアドレス欄に表示される右記のXXXXXXの数字を入力してください。（https://www.chatwork.com/#!ridXXXXXX）</p>
                    </td>
                </tr>
            </table>
            <input type="hidden" name="action" value="update" />
            <input type="hidden" name="page_options" value="impath_cw_api_token,impath_cw_roomid" />
            <p class="submit"><input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" /></p>
        </form>
    </div>
    </div>
<?php
}
?>
