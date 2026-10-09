<?php
if (!defined('ABSPATH')) { exit; }

final class MR_Bridge_Backup_V1 {
    const REST_NAMESPACE = 'mr-bridge/v1';
    const MAX_FILE_CHUNK = 4194304; // 4 MiB
    const MAX_DB_ROWS = 200;
    const TOKEN_OPTION = 'mr_bridge_backup_token_v1';
    const RECEIPTS_OPTION = 'mr_bridge_backup_receipts_v1';
    const MAX_RECEIPTS = 12;

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_filter('rest_pre_serve_request', array(__CLASS__, 'serve_raw_response'), 10, 4);
    }

    public static function register_routes() {
        foreach (array(
            '/backup/info' => 'info',
            '/backup/list' => 'list_dir',
            '/backup/file' => 'file_chunk',
            '/backup/db/tables' => 'db_tables',
            '/backup/db/schema' => 'db_schema',
            '/backup/db/state' => 'db_state',
            '/backup/db/chunk' => 'db_chunk',
        ) as $route => $callback) {
            register_rest_route(self::REST_NAMESPACE, $route, array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, $callback),
                'permission_callback' => array(__CLASS__, 'can_backup'),
            ));
        }
        register_rest_route(self::REST_NAMESPACE, '/backup/receipt', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'receipt'),
            'permission_callback' => array(__CLASS__, 'can_backup'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/backup/receipts', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'receipts'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/backup/client/windows', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'windows_client'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/backup/token/revoke', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'revoke_token'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));
    }

    public static function can_manage() {
        return get_option('mr_bridge_enabled', '1') === '1' && current_user_can('manage_options');
    }

    private static function request_backup_token() {
        $header = '';
        if (isset($_SERVER['HTTP_X_MR_BACKUP_TOKEN'])) {
            $header = trim((string)$_SERVER['HTTP_X_MR_BACKUP_TOKEN']);
        }
        return $header;
    }

    private static function token_valid($token) {
        if ($token === '') { return false; }
        $saved = get_option(self::TOKEN_OPTION, array());
        if (!is_array($saved) || empty($saved['sha256'])) { return false; }
        return hash_equals((string)$saved['sha256'], hash('sha256', $token));
    }

    public static function can_backup() {
        if (get_option('mr_bridge_enabled', '1') !== '1') { return false; }
        if (current_user_can('manage_options')) { return true; }
        return self::token_valid(self::request_backup_token());
    }

    private static function rotate_token() {
        try {
            $token = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $token = wp_generate_password(64, false, false);
        }
        update_option(self::TOKEN_OPTION, array(
            'sha256'=>hash('sha256', $token),
            'created_at'=>current_time('mysql', true),
        ), false);
        return $token;
    }

    public static function revoke_token() {
        delete_option(self::TOKEN_OPTION);
        MR_Bridge::log('backup_token_revoked');
        return rest_ensure_response(array('ok'=>true,'revoked'=>true));
    }

    public static function receipt(WP_REST_Request $request) {
        $data = $request->get_json_params();
        if (!is_array($data)) {
            return new WP_Error('mr_backup_receipt_json', 'Ricevuta backup non valida.', array('status'=>400));
        }
        $manifest_sha256 = strtolower(trim((string)($data['manifest_sha256'] ?? '')));
        $completed_at = sanitize_text_field((string)($data['completed_at'] ?? ''));
        $format = sanitize_text_field((string)($data['format'] ?? ''));
        $files = (int)($data['files'] ?? 0);
        $bytes = (int)($data['bytes'] ?? 0);
        $db_tables = (int)($data['db_tables'] ?? 0);
        $db_sql_bytes = (int)($data['db_sql_bytes'] ?? 0);

        if (!preg_match('/^[a-f0-9]{64}$/', $manifest_sha256)) {
            return new WP_Error('mr_backup_receipt_sha', 'SHA-256 manifest non valido.', array('status'=>400));
        }
        if (!in_array($format, array('mr-bridge-local-backup-v2','mr-bridge-local-backup-v1'), true)) {
            return new WP_Error('mr_backup_receipt_format', 'Formato backup non riconosciuto.', array('status'=>400));
        }
        $ts = strtotime($completed_at);
        if (!$ts || $ts > time() + 300 || $ts < time() - (45 * DAY_IN_SECONDS)) {
            return new WP_Error('mr_backup_receipt_time', 'Data completamento backup non valida.', array('status'=>400));
        }
        if ($files < 1 || $bytes < 1 || $db_tables < 1 || $db_sql_bytes < 1) {
            return new WP_Error('mr_backup_receipt_counts', 'Ricevuta backup incompleta.', array('status'=>400));
        }

        $receipt = array(
            'receipt_id' => 'mrrcpt-' . gmdate('YmdHis', $ts) . '-' . substr($manifest_sha256, 0, 12),
            'received_at' => current_time('mysql', true),
            'completed_at' => gmdate('c', $ts),
            'format' => $format,
            'manifest_sha256' => $manifest_sha256,
            'files' => $files,
            'bytes' => $bytes,
            'db_tables' => $db_tables,
            'db_sql_bytes' => $db_sql_bytes,
        );
        $items = get_option(self::RECEIPTS_OPTION, array());
        if (!is_array($items)) { $items = array(); }
        foreach ($items as $row) {
            if (is_array($row) && hash_equals((string)($row['manifest_sha256'] ?? ''), $manifest_sha256)) {
                return rest_ensure_response(array('ok'=>true,'idempotent'=>true,'receipt'=>$row));
            }
        }
        $items[] = $receipt;
        if (count($items) > self::MAX_RECEIPTS) { $items = array_slice($items, -self::MAX_RECEIPTS); }
        update_option(self::RECEIPTS_OPTION, $items, false);
        MR_Bridge::log('backup_receipt_recorded', array(
            'receipt_id'=>$receipt['receipt_id'],
            'manifest_sha256'=>$manifest_sha256,
            'files'=>$files,
            'bytes'=>$bytes,
            'db_tables'=>$db_tables,
        ));
        return rest_ensure_response(array('ok'=>true,'idempotent'=>false,'receipt'=>$receipt));
    }

    public static function receipts() {
        $items = get_option(self::RECEIPTS_OPTION, array());
        if (!is_array($items)) { $items = array(); }
        return rest_ensure_response(array('ok'=>true,'receipts'=>array_values(array_reverse($items))));
    }

    public static function latest_receipt($max_age_seconds = 86400) {
        $items = get_option(self::RECEIPTS_OPTION, array());
        if (!is_array($items) || !$items) { return null; }
        for ($i=count($items)-1; $i>=0; $i--) {
            $row=$items[$i];
            if (!is_array($row) || empty($row['completed_at'])) { continue; }
            $ts=strtotime((string)$row['completed_at']);
            if ($ts && (time()-$ts) <= (int)$max_age_seconds) { return $row; }
        }
        return null;
    }

    public static function windows_client() {
        $template = __DIR__ . '/../assets/mr-bridge-backup-windows.ps1.tpl';
        if (!is_file($template) || !is_readable($template)) {
            return new WP_Error('mr_backup_client_missing', 'Template client Windows non disponibile.', array('status'=>500));
        }
        $token = self::rotate_token();
        $script = file_get_contents($template);
        if ($script === false) {
            return new WP_Error('mr_backup_client_read_failed', 'Impossibile leggere il client Windows.', array('status'=>500));
        }
        $base = untrailingslashit(rest_url(self::REST_NAMESPACE));
        $script = str_replace(
            array('__MR_BASE_URL__','__MR_BACKUP_TOKEN__'),
            array($base,$token),
            $script
        );
        MR_Bridge::log('backup_windows_client_issued', array('scope'=>'backup_read_and_receipt'));
        $response = new WP_REST_Response($script, 200);
        $response->header('Content-Type', 'text/plain; charset=utf-8');
        $response->header('Content-Disposition', 'attachment; filename="MR-Bridge-Backup-Windows.ps1"');
        $response->header('X-MR-Raw', '1');
        return $response;
    }

    private static function root() {
        $root = realpath(ABSPATH);
        return $root ? wp_normalize_path($root) : wp_normalize_path(ABSPATH);
    }

    private static function exclusions() {
        return array(
            'wp-content/cache',
            'wp-content/upgrade',
            'wp-content/mr-bridge-tmp',
            'wp-content/mr-bridge-backups',
        );
    }

    private static function clean_relative($relative) {
        $relative = wp_normalize_path(rawurldecode((string)$relative));
        $relative = ltrim($relative, '/');
        if ($relative === '.' || $relative === './') { return ''; }
        if (strpos($relative, chr(0)) !== false) {
            return new WP_Error('mr_backup_bad_path', 'Percorso non valido.', array('status'=>400));
        }
        $parts = array();
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') {
                return new WP_Error('mr_backup_bad_path', 'Percorso non valido.', array('status'=>400));
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    private static function excluded($relative) {
        $relative = trim(wp_normalize_path((string)$relative), '/');
        foreach (self::exclusions() as $ex) {
            if ($relative === $ex || strpos($relative, $ex . '/') === 0) { return true; }
        }
        return false;
    }

    private static function resolve($relative) {
        $relative = self::clean_relative($relative);
        if (is_wp_error($relative)) { return $relative; }
        if (self::excluded($relative)) {
            return new WP_Error('mr_backup_excluded', 'Percorso escluso dal backup.', array('status'=>403));
        }
        $root = self::root();
        $candidate = $root . ($relative === '' ? '' : '/' . $relative);
        $resolved = realpath($candidate);
        if ($resolved === false) {
            return new WP_Error('mr_backup_missing', 'Percorso non trovato.', array('status'=>404));
        }
        $resolved = wp_normalize_path($resolved);
        if ($resolved !== $root && strpos($resolved, $root . '/') !== 0) {
            return new WP_Error('mr_backup_outside_root', 'Percorso fuori dalla root WordPress.', array('status'=>403));
        }
        return array('relative'=>$relative,'absolute'=>$resolved);
    }

    public static function info() {
        global $wpdb;
        return rest_ensure_response(array(
            'ok'=>true,
            'read_only'=>true,
            'mode'=>'browser_pull',
            'site'=>home_url('/'),
            'file_chunk_bytes'=>self::MAX_FILE_CHUNK,
            'db_chunk_rows'=>self::MAX_DB_ROWS,
            'db_prefix'=>(string)$wpdb->prefix,
            'exclusions'=>self::exclusions(),
            'scope'=>'wordpress_files_and_database',
            'server_archive_created'=>false,
            'resume_supported'=>true,
            'automatic_retry'=>true,
            'weekly_windows_client'=>true,
        ));
    }

    public static function list_dir(WP_REST_Request $request) {
        $resolved = self::resolve((string)$request->get_param('path'));
        if (is_wp_error($resolved)) { return $resolved; }
        if (!is_dir($resolved['absolute'])) {
            return new WP_Error('mr_backup_not_dir', 'Il percorso non e una directory.', array('status'=>400));
        }
        $scan = @scandir($resolved['absolute']);
        if ($scan === false) {
            return new WP_Error('mr_backup_scan_failed', 'Impossibile leggere la directory.', array('status'=>500));
        }

        $items = array();
        foreach ($scan as $name) {
            if ($name === '.' || $name === '..') { continue; }
            $rel = $resolved['relative'] === '' ? $name : $resolved['relative'] . '/' . $name;
            $rel = wp_normalize_path($rel);
            if (self::excluded($rel)) { continue; }
            $abs = $resolved['absolute'] . '/' . $name;
            if (is_link($abs)) {
                $items[] = array('name'=>$name,'path'=>$rel,'type'=>'symlink_skipped');
            } elseif (is_dir($abs)) {
                $items[] = array('name'=>$name,'path'=>$rel,'type'=>'dir');
            } elseif (is_file($abs)) {
                $size = @filesize($abs);
                $mtime = @filemtime($abs);
                $items[] = array(
                    'name'=>$name,
                    'path'=>$rel,
                    'type'=>'file',
                    'size'=>$size === false ? 0 : (int)$size,
                    'mtime'=>$mtime === false ? 0 : (int)$mtime,
                );
            }
        }
        usort($items, function($a,$b){ return strcmp((string)$a['name'], (string)$b['name']); });
        return rest_ensure_response(array('ok'=>true,'read_only'=>true,'path'=>$resolved['relative'],'items'=>$items));
    }

    public static function file_chunk(WP_REST_Request $request) {
        $resolved = self::resolve((string)$request->get_param('path'));
        if (is_wp_error($resolved)) { return $resolved; }
        if (!is_file($resolved['absolute']) || is_link($resolved['absolute'])) {
            return new WP_Error('mr_backup_not_file', 'Il percorso non e un file regolare.', array('status'=>400));
        }

        $offset = max(0, (int)$request->get_param('offset'));
        $length = (int)$request->get_param('length');
        if ($length < 1) { $length = self::MAX_FILE_CHUNK; }
        $length = min(self::MAX_FILE_CHUNK, $length);

        $size = @filesize($resolved['absolute']);
        $mtime = @filemtime($resolved['absolute']);
        if ($size === false) {
            return new WP_Error('mr_backup_stat_failed', 'Impossibile leggere il file.', array('status'=>500));
        }
        $size = (int)$size;
        if ($offset > $size) {
            return new WP_Error('mr_backup_offset', 'Offset non valido.', array('status'=>416));
        }

        $fh = @fopen($resolved['absolute'], 'rb');
        if (!$fh) {
            return new WP_Error('mr_backup_open_failed', 'Impossibile aprire il file.', array('status'=>500));
        }
        if ($offset > 0 && fseek($fh, $offset) !== 0) {
            fclose($fh);
            return new WP_Error('mr_backup_seek_failed', 'Impossibile leggere il file.', array('status'=>500));
        }
        $data = fread($fh, $length);
        fclose($fh);
        if ($data === false) {
            return new WP_Error('mr_backup_read_failed', 'Impossibile leggere il file.', array('status'=>500));
        }

        $response = new WP_REST_Response($data, 200);
        $response->header('Content-Type', 'application/octet-stream');
        $response->header('Content-Length', (string)strlen($data));
        $response->header('X-MR-Raw', '1');
        $response->header('X-MR-SHA256', hash('sha256', $data));
        $response->header('X-MR-File-Size', (string)$size);
        $response->header('X-MR-File-MTime', (string)($mtime === false ? 0 : (int)$mtime));
        return $response;
    }

    private static function allowed_tables() {
        global $wpdb;
        $like = $wpdb->esc_like($wpdb->prefix) . '%';
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        $out = array();
        foreach ((array)$tables as $table) {
            if (is_string($table) && strpos($table, $wpdb->prefix) === 0) { $out[] = $table; }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    private static function checked_table($table) {
        $table = (string)$table;
        if (!in_array($table, self::allowed_tables(), true)) {
            return new WP_Error('mr_backup_table_denied', 'Tabella non autorizzata.', array('status'=>403));
        }
        return $table;
    }

    private static function qi($identifier) {
        $q = chr(96);
        return $q . str_replace($q, $q . $q, (string)$identifier) . $q;
    }

    public static function db_tables() {
        return rest_ensure_response(array('ok'=>true,'read_only'=>true,'tables'=>self::allowed_tables()));
    }

    public static function db_schema(WP_REST_Request $request) {
        global $wpdb;
        $table = self::checked_table($request->get_param('table'));
        if (is_wp_error($table)) { return $table; }
        $row = $wpdb->get_row('SHOW CREATE TABLE ' . self::qi($table), ARRAY_A);
        if (!$row || count($row) < 2) {
            return new WP_Error('mr_backup_schema_failed', 'Schema non disponibile.', array('status'=>500));
        }
        $vals = array_values($row);
        $create = (string)$vals[1];
        return rest_ensure_response(array(
            'ok'=>true,
            'read_only'=>true,
            'table'=>$table,
            'create_sql'=>$create,
            'sha256'=>hash('sha256',$create),
        ));
    }

    private static function table_state($table) {
        global $wpdb;
        $count = $wpdb->get_var('SELECT COUNT(*) FROM ' . self::qi($table));
        $updated = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT TABLE_ROWS, UPDATE_TIME, DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',
                DB_NAME,
                $table
            ),
            ARRAY_A
        );
        $checksum_row = $wpdb->get_row('CHECKSUM TABLE ' . self::qi($table), ARRAY_A);
        $checksum = '';
        if (is_array($checksum_row)) {
            foreach ($checksum_row as $key=>$value) {
                if (stripos((string)$key, 'checksum') !== false) { $checksum = (string)$value; break; }
            }
        }
        return array(
            'rows_exact'=>(int)$count,
            'table_rows_estimate'=>(int)($updated['TABLE_ROWS'] ?? 0),
            'update_time'=>(string)($updated['UPDATE_TIME'] ?? ''),
            'data_length'=>(int)($updated['DATA_LENGTH'] ?? 0),
            'index_length'=>(int)($updated['INDEX_LENGTH'] ?? 0),
            'checksum'=>$checksum,
        );
    }

    public static function db_state(WP_REST_Request $request) {
        $table = self::checked_table($request->get_param('table'));
        if (is_wp_error($table)) { return $table; }
        return rest_ensure_response(array(
            'ok'=>true,
            'read_only'=>true,
            'table'=>$table,
            'state'=>self::table_state($table),
        ));
    }

    private static function sql_value($value) {
        global $wpdb;
        if ($value === null) { return 'NULL'; }
        return $wpdb->prepare('%s', (string)$value);
    }

    private static function db_order_clause($table) {
        global $wpdb;
        $keys = $wpdb->get_results("SHOW KEYS FROM " . self::qi($table) . " WHERE Key_name='PRIMARY'", ARRAY_A);
        $ordered = array();
        foreach ((array)$keys as $row) {
            $seq = isset($row['Seq_in_index']) ? (int)$row['Seq_in_index'] : 0;
            $col = isset($row['Column_name']) ? (string)$row['Column_name'] : '';
            if ($seq > 0 && $col !== '') { $ordered[$seq] = $col; }
        }
        if (!empty($ordered)) {
            ksort($ordered, SORT_NUMERIC);
            return ' ORDER BY ' . implode(',', array_map(array(__CLASS__,'qi'), array_values($ordered)));
        }
        $cols = $wpdb->get_results('SHOW COLUMNS FROM ' . self::qi($table), ARRAY_A);
        $fallback = array();
        foreach ((array)$cols as $row) {
            if (!empty($row['Field'])) { $fallback[] = self::qi((string)$row['Field']); }
        }
        return empty($fallback) ? '' : ' ORDER BY ' . implode(',', $fallback);
    }

    public static function db_chunk(WP_REST_Request $request) {
        global $wpdb;
        $table = self::checked_table($request->get_param('table'));
        if (is_wp_error($table)) { return $table; }

        $offset = max(0, (int)$request->get_param('offset'));
        $limit = (int)$request->get_param('limit');
        if ($limit < 1) { $limit = self::MAX_DB_ROWS; }
        $limit = min(self::MAX_DB_ROWS, $limit);

        $sql = 'SELECT * FROM ' . self::qi($table) . self::db_order_clause($table) . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset;
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return new WP_Error('mr_backup_db_read_failed', 'Impossibile leggere la tabella.', array('status'=>500));
        }

        $out = '';
        foreach ($rows as $row) {
            $columns = array();
            $values = array();
            foreach ($row as $column=>$value) {
                $columns[] = self::qi($column);
                $values[] = self::sql_value($value);
            }
            $out .= 'INSERT INTO ' . self::qi($table) . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n";
        }

        $response = new WP_REST_Response($out, 200);
        $response->header('Content-Type', 'text/plain; charset=utf-8');
        $response->header('Content-Length', (string)strlen($out));
        $response->header('X-MR-Raw', '1');
        $response->header('X-MR-SHA256', hash('sha256', $out));
        $response->header('X-MR-Rows', (string)count($rows));
        return $response;
    }

    public static function serve_raw_response($served, $result, $request, $server) {
        if ($served || !($result instanceof WP_REST_Response)) { return $served; }
        $headers = $result->get_headers();
        if (!isset($headers['X-MR-Raw']) || (string)$headers['X-MR-Raw'] !== '1') { return $served; }
        echo $result->get_data();
        return true;
    }

    public static function admin_menu() {
        add_management_page(
            'MR Bridge Backup',
            'MR Bridge Backup',
            'manage_options',
            'mr-bridge-backup',
            array(__CLASS__,'admin_page')
        );
    }

    public static function admin_page() {
        if (!current_user_can('manage_options')) { return; }
        $base = esc_url_raw(rest_url(self::REST_NAMESPACE));
        $nonce = wp_create_nonce('wp_rest');
        ?>
        <div class="wrap">
          <h1>MR Bridge — Backup locale Yoganostress</h1>
          <p><strong>Nessun archivio completo viene creato sul server.</strong> File WordPress e database vengono copiati direttamente nella cartella scelta sul PC.</p>
          <p>Il backup non comprende caselle email o configurazioni dell'account cPanel.</p>
          <p>
            <button id="mr-backup-start" class="button button-primary">Scegli cartella sul PC e avvia backup</button>
            <button id="mr-backup-cancel" class="button" disabled>Annulla</button>
          </p>
          <hr>
          <h2>Backup settimanale automatico Windows</h2>
          <p>Il client locale riprende automaticamente i backup interrotti, ritenta in caso di rete instabile e usa sempre la stessa cartella principale.</p>
          <p>
            <button id="mr-backup-windows" class="button button-secondary">Scarica e configura backup settimanale</button>
            <button id="mr-backup-token-revoke" class="button">Revoca client Windows</button>
          </p>
          <p><small>Scaricare di nuovo il client genera un nuovo token e rende inutilizzabile il precedente.</small></p>
          <pre id="mr-backup-status" style="background:#fff;padding:12px;max-height:420px;overflow:auto;white-space:pre-wrap"></pre>
        </div>
        <script>
        (() => {
          const BASE = <?php echo wp_json_encode($base); ?>;
          const NONCE = <?php echo wp_json_encode($nonce); ?>;
          const status = document.getElementById('mr-backup-status');
          const startBtn = document.getElementById('mr-backup-start');
          const cancelBtn = document.getElementById('mr-backup-cancel');
          const windowsBtn = document.getElementById('mr-backup-windows');
          const revokeBtn = document.getElementById('mr-backup-token-revoke');
          let cancelled = false;
          let controller = null;

          const log = s => { status.textContent += s + "\n"; status.scrollTop = status.scrollHeight; };
          const hex = b => [...new Uint8Array(b)].map(x=>x.toString(16).padStart(2,'0')).join('');
          const sha256 = async b => hex(await crypto.subtle.digest('SHA-256', b));
          const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
          const api = async (path, raw=false) => {
            let attempt=0;
            while (true) {
              if (cancelled) throw new Error('Backup annullato');
              try {
                const r = await fetch(BASE + path, {
                  headers: {'X-WP-Nonce': NONCE},
                  signal: controller.signal,
                  cache: 'no-store'
                });
                if (!r.ok) throw new Error('HTTP ' + r.status + ' su ' + path);
                return raw ? r : r.json();
              } catch(e) {
                attempt++;
                if (cancelled || attempt >= 12) throw e;
                const delay = Math.min(60000, Math.pow(2,Math.min(attempt,5))*2500);
                log('Connessione interrotta: nuovo tentativo automatico tra '+Math.round(delay/1000)+' secondi...');
                await sleep(delay);
              }
            }
          };
          const apiPost = async (path, body) => {
            let attempt=0;
            while (true) {
              if (cancelled) throw new Error('Backup annullato');
              try {
                const r = await fetch(BASE + path, {
                  method:'POST',
                  headers:{'X-WP-Nonce':NONCE,'Content-Type':'application/json'},
                  body:JSON.stringify(body),
                  signal:controller.signal,
                  cache:'no-store'
                });
                if (!r.ok) throw new Error('HTTP '+r.status+' su '+path);
                return r.json();
              } catch(e) {
                attempt++;
                if (cancelled || attempt >= 12) throw e;
                const delay=Math.min(60000,Math.pow(2,Math.min(attempt,5))*2500);
                log('Ricevuta backup: nuovo tentativo tra '+Math.round(delay/1000)+' secondi...');
                await sleep(delay);
              }
            }
          };
          const getDir = async (root,path) => {
            let d = root;
            for (const part of (path||'').split('/').filter(Boolean)) {
              d = await d.getDirectoryHandle(part,{create:true});
            }
            return d;
          };
          const writeText = async (dir,name,text) => {
            const h = await dir.getFileHandle(name,{create:true});
            const w = await h.createWritable();
            await w.write(text);
            await w.close();
          };
          const fmt = n => (n/1073741824).toFixed(2) + ' GB';

          async function scan(path,files,dirs,skipped) {
            const d = await api('/backup/list' + (path ? '?path='+encodeURIComponent(path) : ''));
            for (const item of d.items) {
              if (item.type === 'dir') {
                dirs.push(item.path);
                await scan(item.path,files,dirs,skipped);
              } else if (item.type === 'file') {
                files.push(item);
              } else {
                skipped.push(item.path);
              }
            }
          }

          async function downloadFile(siteDir,item,manifest,index,total) {
            const slash = item.path.lastIndexOf('/');
            const parent = slash >= 0 ? item.path.slice(0,slash) : '';
            const name = slash >= 0 ? item.path.slice(slash+1) : item.path;
            const dir = await getDir(siteDir,parent);
            const fh = await dir.getFileHandle(name,{create:true});
            const w = await fh.createWritable();
            let offset=0, baselineSize=null, baselineMtime=null, chunks=[];
            try {
              while (offset < item.size) {
                const len = Math.min(4194304,item.size-offset);
                const r = await api('/backup/file?path='+encodeURIComponent(item.path)+'&offset='+offset+'&length='+len,true);
                const b = await r.arrayBuffer();
                const remote = r.headers.get('X-MR-SHA256') || '';
                const local = await sha256(b);
                if (!remote || remote !== local) throw new Error('SHA-256 non valido: '+item.path);
                const sz = Number(r.headers.get('X-MR-File-Size'));
                const mt = Number(r.headers.get('X-MR-File-MTime'));
                if (baselineSize === null) { baselineSize=sz; baselineMtime=mt; }
                if (sz!==baselineSize || mt!==baselineMtime || sz!==item.size || mt!==item.mtime) {
                  throw new Error('File cambiato durante il backup: '+item.path);
                }
                await w.write({type:'write',position:offset,data:new Uint8Array(b)});
                chunks.push({offset,bytes:b.byteLength,sha256:local});
                offset += b.byteLength;
                if (!b.byteLength && offset < item.size) throw new Error('Chunk vuoto: '+item.path);
              }
              await w.truncate(item.size);
            } finally {
              await w.close();
            }
            manifest.files.push({path:item.path,size:item.size,mtime:item.mtime,chunks});
            if (index % 20 === 0 || index === total) {
              log('File '+index+'/'+total+' — '+fmt(manifest.files.reduce((a,x)=>a+x.size,0))+' copiati');
            }
          }

          async function backupDatabase(dbDir,manifest) {
            log('Database: elenco tabelle...');
            const td = await api('/backup/db/tables');
            const fh = await dbDir.getFileHandle('database.sql',{create:true});
            const w = await fh.createWritable();
            let pos=0;
            const enc=new TextEncoder();
            const put=async text=>{
              const b=enc.encode(text);
              await w.write({type:'write',position:pos,data:b});
              pos+=b.byteLength;
            };
            const bt=String.fromCharCode(96);
            await put("-- MR Bridge Yoganostress database backup\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            try {
              for (let ti=0; ti<td.tables.length; ti++) {
                const table=td.tables[ti];
                log('Database '+(ti+1)+'/'+td.tables.length+': '+table);
                const before=await api('/backup/db/state?table='+encodeURIComponent(table));
                const schema=await api('/backup/db/schema?table='+encodeURIComponent(table));
                const safe=table.split(bt).join(bt+bt);
                await put('DROP TABLE IF EXISTS '+bt+safe+bt+';\n'+schema.create_sql+';\n');
                let offset=0, tableChunks=[];
                while (true) {
                  const r=await api('/backup/db/chunk?table='+encodeURIComponent(table)+'&offset='+offset+'&limit=200',true);
                  const b=await r.arrayBuffer();
                  const rows=Number(r.headers.get('X-MR-Rows')||0);
                  const remote=r.headers.get('X-MR-SHA256')||'';
                  const local=await sha256(b);
                  if (!remote || remote!==local) throw new Error('SHA-256 database non valido: '+table);
                  if (b.byteLength) {
                    await w.write({type:'write',position:pos,data:new Uint8Array(b)});
                    pos+=b.byteLength;
                  }
                  tableChunks.push({offset,rows,bytes:b.byteLength,sha256:local});
                  if (rows===0) break;
                  offset+=rows;
                }
                const after=await api('/backup/db/state?table='+encodeURIComponent(table));
                const stable =
                  before.state.rows_exact===after.state.rows_exact &&
                  before.state.update_time===after.state.update_time &&
                  before.state.data_length===after.state.data_length &&
                  before.state.index_length===after.state.index_length &&
                  before.state.checksum===after.state.checksum;
                if (!stable) throw new Error('Tabella cambiata durante il backup: '+table+'. Riprova quando il sito e meno attivo.');
                await put("\n");
                manifest.database.tables.push({
                  table,
                  rows:before.state.rows_exact,
                  schema_sha256:schema.sha256,
                  chunks:tableChunks,
                  stable:true
                });
              }
              await put("SET FOREIGN_KEY_CHECKS=1;\n");
            } finally {
              await w.close();
            }
            manifest.database.sql_bytes=pos;
          }

          startBtn.addEventListener('click', async () => {
            if (!window.showDirectoryPicker) {
              alert('Per il backup diretto sul PC usa Chrome o Edge aggiornato.');
              return;
            }
            cancelled=false;
            controller=new AbortController();
            status.textContent='';
            startBtn.disabled=true;
            cancelBtn.disabled=false;
            let backupDir=null;
            try {
              const chosen=await window.showDirectoryPicker({mode:'readwrite'});
              const now=new Date();
              const stamp=
                now.getFullYear()+
                String(now.getMonth()+1).padStart(2,'0')+
                String(now.getDate()).padStart(2,'0')+'-'+
                String(now.getHours()).padStart(2,'0')+
                String(now.getMinutes()).padStart(2,'0')+
                String(now.getSeconds()).padStart(2,'0');
              backupDir=await chosen.getDirectoryHandle('yoganostress-backup-'+stamp,{create:true});
              const siteDir=await backupDir.getDirectoryHandle('site',{create:true});
              const dbDir=await backupDir.getDirectoryHandle('database',{create:true});
              const info=await api('/backup/info');

              const files=[],dirs=[],skipped=[];
              log('Scansione file WordPress...');
              await scan('',files,dirs,skipped);
              const totalBytes=files.reduce((a,x)=>a+x.size,0);
              log('Trovati '+files.length+' file ('+fmt(totalBytes)+'). Copia sul PC...');

              for (const d of dirs) await getDir(siteDir,d);

              const manifest={
                format:'mr-bridge-local-backup-v1',
                created_at:new Date().toISOString(),
                site:info.site,
                scope:info.scope,
                exclusions:info.exclusions,
                skipped_symlinks:skipped,
                planned_files:files.length,
                planned_bytes:totalBytes,
                files:[],
                database:{tables:[],sql_bytes:0},
                integrity:{
                  algorithm:'SHA-256',
                  file_chunks_verified:true,
                  database_chunks_verified:true,
                  database_tables_stable:true
                }
              };

              for (let i=0;i<files.length;i++) {
                await downloadFile(siteDir,files[i],manifest,i+1,files.length);
              }
              await backupDatabase(dbDir,manifest);

              manifest.completed_at=new Date().toISOString();
              manifest.completed=true;
              manifest.downloaded_files=manifest.files.length;
              manifest.downloaded_bytes=manifest.files.reduce((a,x)=>a+x.size,0);

              const manifestText=JSON.stringify(manifest,null,2);
              await writeText(backupDir,'backup-manifest.json',manifestText);
              await writeText(
                backupDir,
                'LEGGIMI.txt',
                'Backup Yoganostress creato da MR Bridge.\nComprende file WordPress e database.\nNon comprende email o configurazioni cPanel.\nOgni blocco trasferito e stato verificato con SHA-256.\n'
              );
              try {
                const encoded=new TextEncoder().encode(manifestText);
                const manifestSha=await sha256(encoded.buffer);
                const receipt=await apiPost('/backup/receipt',{
                  format:manifest.format,
                  completed_at:manifest.completed_at,
                  manifest_sha256:manifestSha,
                  files:manifest.downloaded_files,
                  bytes:manifest.downloaded_bytes,
                  db_tables:manifest.database.tables.length,
                  db_sql_bytes:manifest.database.sql_bytes
                });
                log('Ricevuta backup registrata: '+receipt.receipt.receipt_id);
              } catch(receiptError) {
                log('Backup completo, ma ricevuta server non registrata: '+String(receiptError.message||receiptError));
              }

              log('BACKUP COMPLETATO E VERIFICATO.');
              log('File: '+manifest.downloaded_files+' — '+fmt(manifest.downloaded_bytes));
              log('Database: '+manifest.database.tables.length+' tabelle.');
            } catch(e) {
              if (backupDir) {
                try {
                  await writeText(backupDir,'BACKUP-INCOMPLETO.txt','Backup non completato: '+String(e.message||e));
                } catch(_e) {}
              }
              log('ERRORE: '+String(e.message||e));
            } finally {
              startBtn.disabled=false;
              cancelBtn.disabled=true;
              controller=null;
            }
          });

          windowsBtn.addEventListener('click', async () => {
            windowsBtn.disabled=true;
            try {
              const r=await fetch(BASE+'/backup/client/windows',{
                method:'POST',
                headers:{'X-WP-Nonce':NONCE,'Content-Type':'application/json'},
                body:'{}',
                cache:'no-store'
              });
              if(!r.ok) throw new Error('HTTP '+r.status);
              const blob=await r.blob();
              const url=URL.createObjectURL(blob);
              const a=document.createElement('a');
              a.href=url;
              a.download='MR-Bridge-Backup-Windows.ps1';
              document.body.appendChild(a);
              a.click();
              a.remove();
              URL.revokeObjectURL(url);
              log('Client Windows scaricato. Eseguilo una sola volta per scegliere cartella, giorno e ora.');
            } catch(e) {
              log('ERRORE client Windows: '+String(e.message||e));
            } finally { windowsBtn.disabled=false; }
          });

          revokeBtn.addEventListener('click', async () => {
            if(!confirm('Revocare il client Windows di backup?')) return;
            revokeBtn.disabled=true;
            try {
              const r=await fetch(BASE+'/backup/token/revoke',{
                method:'POST',
                headers:{'X-WP-Nonce':NONCE,'Content-Type':'application/json'},
                body:'{}',
                cache:'no-store'
              });
              if(!r.ok) throw new Error('HTTP '+r.status);
              log('Client Windows revocato.');
            } catch(e) {
              log('ERRORE revoca: '+String(e.message||e));
            } finally { revokeBtn.disabled=false; }
          });

          cancelBtn.addEventListener('click',()=>{
            cancelled=true;
            if (controller) controller.abort();
            log('Annullamento richiesto...');
          });
        })();
        </script>
        <?php
    }
}

MR_Bridge_Backup_V1::init();

