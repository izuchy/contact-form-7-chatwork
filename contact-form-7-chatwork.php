<?php
/*
Plugin Name: Contact Form 7 with ChatWork
Plugin URI:
Description: ChatWork にフォームの内容を送信します。
Author: IMPATH Inc.
Version: 1.0.1
Author URI: http://impath.co.jp
*/

register_activation_hook( __FILE__, 'cf7_cw_activate' );
function cf7_cw_activate() {
    // Created database table
    global $wpdb;
    $table_name = $wpdb->prefix . 'cf7_cw';
    $sql = "CREATE TABLE " . $table_name . " (
          id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
          roomid bigint(20) NOT NULL NOT NULL,
          formid bigint(20) DEFAULT NULL,
          active boolean DEFAULT 0,
          UNIQUE KEY id (id)
        )
        CHARACTER SET 'utf8';";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Update for old version
    $query = 'select * from ' . $wpdb->prefix . 'options where option_name = "impath_cw_roomid" and option_value != "";';
    $results = $wpdb->get_results($query, 'ARRAY_A');
    if (count($results) > 0) {
        $wpdb->insert($wpdb->prefix . 'cf7_cw', array('roomid' => $results[0]['option_value'], 'formid' => null, 'active' => true));
        $wpdb->query('delete from ' . $wpdb->prefix . 'options where option_name = "impath_cw_roomid"');
    }
}

add_action( 'wpcf7_mail_sent', 'impath_send_cw_message');
function impath_send_cw_message( $cf7 ){

    if(!get_option('impath_cw_api_token')) return;

    // Contact Form 7 > 3.9
    if(!method_exists($cf7, 'replace_mail_tags')) {
        $mail_properties = $cf7->get_properties();
        $mail = $mail_properties['mail'];
        $mail_subject_template = $mail['subject'];
        $mail_body_template = $mail['body'];
        $mail_additional_headers_template = $mail['additional_headers'];
        $mail_subject = wpcf7_mail_replace_tags($mail_subject_template);
        $mail_body = wpcf7_mail_replace_tags($mail_body_template);
    }elseif(method_exists($cf7, 'replace_mail_tags')) {
        $mail_template = $cf7->setup_mail_template($cf7->mail, 'mail' );
        $mail_subject = $cf7->replace_mail_tags($mail_template['subject']);
        $mail_body = $cf7->replace_mail_tags($mail_template['body']);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'cf7_cw';
    $query = "select * from $table_name where formid = $cf7->id";
    $results = $wpdb->get_results($query, 'ARRAY_A');

    if (is_null($results[0]['formid'])) {
        $query = "select roomid from $table_name where active = 1 and formid is NULL order by id asc limit 1";
        $results = $wpdb->get_results($query, 'ARRAY_A');
    }
    $roomid = $results[0]['roomid'];

    if (empty($roomid)) return;

    $body = "[info][title]" . $mail_subject . "[/title]" . $mail_body . "\n[/info]";
    $key = get_option('impath_cw_api_token');

    $url = 'https://api.chatwork.com/v1/rooms/' . $roomid . '/messages';
    $data = array(
        'body' => $body
    );
    $headers = array(
        'X-ChatWorkToken: ' . $key
    );
    $options = array('http' => array(
        'method' => 'POST',
        'content' => http_build_query($data),
        'header' => implode("\r\n", $headers),
    ));
    $contents = file_get_contents($url, false, stream_context_create($options));
}

add_action('admin_menu', 'impath_cf7cw_admin_menu');
function impath_cf7cw_admin_menu(){
    add_options_page('ChatWork連携設定', 'ChatWork連携設定', 8, __FILE__, 'impath_cf7cw_admin_opt_page');
}

function getArrayValueByKey($array, $key){
    $res = array();
    foreach ($array as $val) {
        $res[] = $val[$key];
    }
    return $res;
}
function impath_cf7cw_admin_opt_page(){
    global $wpdb;
    $table_name = $wpdb->prefix . 'cf7_cw';

    $query = "select * from $table_name";
    $record = $wpdb->get_results($query, 'ARRAY_A');

    if (! empty($_POST) && isset($_POST['impath_cw'])) {
        foreach ($_POST['impath_cw'] as $values) {
            if (empty($values['formid']) || empty($values['roomid'])) continue;
            if (in_array($values['id'], getArrayValueByKey($record, 'id'))) {
                // Update
                $wpdb->update($table_name, $values, array('id' => $values['id']));
            } else {
                // New saved
                $values['active'] = true;
                $wpdb->insert($table_name, $values);
                $row_count++;
            }
        }

        // Delete action
        if (isset($_POST['del_room'])) {
            $wpdb->update($table_name, array('active' => false), array('id' => $_POST['del_room']));
        }

        // Update api token
        if (isset($_POST['impath_cw_api_token'])) {
            update_option('impath_cw_api_token', $_POST['impath_cw_api_token']);
        }
    }

    $query = "select max(id) as row_count from $table_name";
    $row_count = $wpdb->get_results($query, 'ARRAY_A');
    $row_count = is_null($row_count[0]['row_count']) ? 1 : $row_count[0]['row_count'];

    $query = "select * from $table_name where active = 1";
    $results = $wpdb->get_results($query, 'ARRAY_A');
?>
    <script src="//ajax.googleapis.com/ajax/libs/jquery/2.1.4/jquery.min.js"></script>

    <div class="wrap">
        <div id="icon-options-general" class="icon32">
            <br>
        </div>
        <h2>Contact Form 7 + ChatWork連携オプション</h2>
        <form id="cf7cw" method="post" action="">
            <?php wp_nonce_field('update-options'); ?>
            <p>Contact Form 7のフォームからメッセージが送られるとChatWorkの指定のチャットルームに送信されます。</p>
            <p style="color:red"><strong>本プラグインを動作させるには必ず <a target="_blank" href="http://wordpress.org/plugins/contact-form-7/">Contact Form 7</a> がインストールされている必要があります。</strong></p>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">
                        <label for="api_token">ChatWork APIトークン</label>
                    </th>
                    <td>
                        <p class="description">本プラグインの動作にはChatWork社のAPIトークンが必要になります。</p>
                        <input id="api_token" type="text" class="regular-text ltr" name="impath_cw_api_token" value="<?php echo get_option('impath_cw_api_token'); ?>" />
                    </td>
                </tr>
            </table>

            <table class="form-table">
                <tr>
                    <th></th>
                    <th>投稿先のチャットルームのルームID<br>ルームIDはChatWorkでルームを選択した際に、ブラウザのアドレス欄に表示される右記のXXXXXXの数字を入力してください。（https://www.chatwork.com/#!ridXXXXXX）</th>
                    <th>コンタクトフォームのフォームID<br>コンタクトフォームで生成したタグに表示される右記のXXXの数字を入力してください。（[contact-form-7 id="XXX"]）</th>
                </tr>

                <?php
                    if (count($results) > 0) :
                        foreach ($results as $i => $result) :
                ?>
                    <tr class="line">
                        <th>Chat room <?php echo $i + 1; ?></th>
                        <td style="display: none;"><input class="ids" type="hidden" name="impath_cw[<?php echo $result['id']; ?>][id]" value="<?php echo $result['id']; ?>" /></td>
                        <td><input type="text" class="regular-text ltr" name="impath_cw[<?php echo $result['id']; ?>][roomid]" value="<?php echo $result['roomid']; ?>" /></td>
                        <td><input type="text" class="regular-text ltr" name="impath_cw[<?php echo $result['id']; ?>][formid]" value="<?php echo $result['formid']; ?>" /><button type="submit" class="button-secondary" name="del_room" value="<?php echo $result['id']; ?>" >削除</button></td>
                    </tr>
                <?php
                        endforeach;
                    else :
                ?>
                    <tr class="line">
                        <th>Chat room 1</th>
                        <td style="display: none;"><input class="ids" type="hidden" name="impath_cw[1][id]" value="1" /></td>
                        <td><input type="text" class="regular-text ltr" name="impath_cw[1][roomid]" value="" /></td>
                        <td><input type="text" class="regular-text ltr" name="impath_cw[1][formid]" value="" /></td>
                    </tr>
                <?php
                    endif;
                ?>

                <tr id="add_room_wrapper" valign="top">
                    <th colspan="3" scope="row" style="text-align:center;">
                        <a class="button-secondary" id="add_roomid" href="javascript:void(0);">通知先チャットルームを追加</a>
                    </th>
                </tr>
            </table>
            <input type="hidden" name="action" value="update" />
            <input id="send_value" type="hidden" name="page_options" />
            <p class="submit"><input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" /></p>
        </form>
    </div>
    <script>
        $(document).ready(function() {
            var maxId = <?php echo $row_count; ?>;
            $('#add_roomid').click(function() {
                var lineCount = $('.line').length + 1;
                maxId += 1;
                var tag = '<tr class="line"><th>Chat room ' + lineCount + '</th><td style="display: none;"><input class="ids" type="hidden" name="impath_cw[' + maxId + '][id]" value="' + maxId + '" /></td><td><input type="text" class="regular-text ltr" name="impath_cw[' + maxId + '][roomid]" /></td><td><input type="text" class="regular-text ltr" name="impath_cw[' + maxId + '][formid]" /></td></tr>';

                $("#add_room_wrapper").before(tag);
            });

            $('#cf7cw').submit(function() {
                var values = 'impath_cw_api_token';
                $('.ids').each(function(i, ele){
                    var id = $(ele).val();
                    roomName = "impath_cw[" + id + "][roomid]";
                    formName = "impath_cw[" + id + "][formid]";
                    values += ',' + roomName + ',' + formName;
                });
                $('#send_value').val(values);
            });
        });
    </script>
<?php
}
?>
