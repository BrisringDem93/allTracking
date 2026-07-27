<?php
// Funzione per creare la tabella dei cookie
function fst_create_cookie_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'fst_user_cookies';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        fst_uid varchar(255) NOT NULL,
        cookies text NOT NULL,
        expiration datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

// Funzione per inserire o aggiornare un cookie nel database
function fst_save_user_cookie($fst_uid, $cookies, $expiration_days = 30) {
    // Sanitize inputs to prevent SQL injection
    $fst_uid = sanitize_text_field($fst_uid);
    $cookies = sanitize_textarea_field($cookies);
    $expiration_days = absint($expiration_days);

    global $wpdb;
    $table_name = $wpdb->prefix . 'fst_user_cookies';

    $expiration = date('Y-m-d H:i:s', strtotime("+$expiration_days days"));
    $created_at = date('Y-m-d H:i:s');

    $existing_entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE fst_uid = %s", $fst_uid));

    if ($existing_entry) {
        // Aggiorna la riga esistente con query preparata
        $sql = $wpdb->prepare(
            "UPDATE $table_name SET cookies = %s, expiration = %s WHERE fst_uid = %s",
            $cookies,
            $expiration,
            $fst_uid
        );
        $wpdb->query($sql);
    } else {
        // Inserisci una nuova riga con query preparata
        $sql = $wpdb->prepare(
            "INSERT INTO $table_name (fst_uid, cookies, expiration, created_at) VALUES (%s, %s, %s, %s)",
            $fst_uid,
            $cookies,
            $expiration,
            $created_at
        );
        $wpdb->query($sql);
    }
}

// Funzione per recuperare il valore del cookie di un utente
function fst_get_user_cookie($fst_uid) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'fst_user_cookies';
    // Recupera il valore del cookie se non è scaduto utilizzando query preparata
    $cookie = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT cookies FROM {$table_name} WHERE fst_uid = %s AND expiration >= NOW()",
            $fst_uid
        )
    );
    return $cookie;
}
