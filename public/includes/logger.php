<?php
/**
 * includes/logger.php
 *
 * Tiny, dependency-free file logger designed for shared hosting.
 * - Writes JSON Lines (one JSON object per line) for easy grep/analysis.
 * - Tries to log outside web root first: /home/<account>/secure/logs/sfm.log
 * - Falls back to /public_html/logs/sfm.log, then system temp if needed.
 * - Silent failure (never throws); will fall back to error_log() if file unwritable.
 * - Simple log rotation when file exceeds a size threshold.
 *
 * Usage:
 *   require_once __DIR__ . '/logger.php';
 *   sfm_log_info('feed generated', ['feed_url' => $url, 'items' => 12]);
 *   sfm_log_error('db insert failed', ['error' => $e->getMessage()]);
 *
 * Optional: override log file at runtime
 *   sfm_logger_path('/absolute/path/to/custom.log');
 */

declare(strict_types=1);

if (!function_exists('sfm_logger_dir')) {

  // --- configuration ---
  // Rotate when log exceeds ~2MB (adjust if needed)
  define('SFM_LOG_MAX_BYTES', 2 * 1024 * 1024);

  // Cache for resolved paths
  $GLOBALS['_sfm_logger_path'] = null;
  $GLOBALS['_sfm_logger_dir']  = null;

  /**
   * Compute best log directory (memoized).
   * Prefers /home/<account>/secure/logs, then /public_html/logs, then sys temp.
   */
  function sfm_logger_dir(): string {
    if (!empty($GLOBALS['_sfm_logger_dir'])) return $GLOBALS['_sfm_logger_dir'];

    // includes/ is under /public_html/includes
    $publicHtml = dirname(__DIR__);          // /.../public_html
    $accountRoot = dirname($publicHtml);     // /.../home/<account>
    $candidates = [
      $accountRoot . '/secure/logs',         // best: outside web root
      $publicHtml . '/logs',                 // fallback: inside web root
      rtrim(sys_get_temp_dir(), '/\\') . '/sfm-logs', // last resort
    ];

    foreach ($candidates as $dir) {
      if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
      }
      if (is_dir($dir) && is_writable($dir)) {
        return $GLOBALS['_sfm_logger_dir'] = $dir;
      }
    }

    // If everything failed, cache temp anyway (may not be writable)
    return $GLOBALS['_sfm_logger_dir'] = end($candidates);
  }

  /**
   * Return the current log file path (memoized).
   */
  function sfm_logger_file(): string {
    if (!empty($GLOBALS['_sfm_logger_path'])) return $GLOBALS['_sfm_logger_path'];
    return $GLOBALS['_sfm_logger_path'] = sfm_logger_dir() . '/sfm.log';
  }

  /**
   * Manually override the log file path at runtime.
   */
  function sfm_logger_path(string $absolutePath): void {
    $dir = dirname($absolutePath);
    if (!is_dir($dir)) {
      @mkdir($dir, 0775, true);
    }
    $GLOBALS['_sfm_logger_dir']  = $dir;
    $GLOBALS['_sfm_logger_path'] = $absolutePath;
  }

  /**
   * Rotate the log file if it exceeds the size threshold.
   */
  function sfm_log_rotate(string $path): void {
    clearstatcache(true, $path);
    if (!is_file($path)) return;
    $size = @filesize($path);
    if ($size === false || $size < SFM_LOG_MAX_BYTES) return;

    $ts   = date('Ymd_His');
    $dir  = dirname($path);
    $base = basename($path, '.log');
    $rot  = $dir . '/' . $base . '-' . $ts . '.log';

    // Best-effort rotation (rename is atomic on same FS)
    @rename($path, $rot);
  }

  /**
   * Mask sensitive values in context (very light heuristic).
   */
  function sfm_log_scrub(array $ctx): array {
    $sensitiveKeys = ['password','pass','secret','token','key','authorization','cookie','set-cookie'];
    $out = [];
    foreach ($ctx as $k => $v) {
      $isSensitive = false;
      foreach ($sensitiveKeys as $needle) {
        if (stripos((string)$k, $needle) !== false) { $isSensitive = true; break; }
      }
      if ($isSensitive) {
        $out[$k] = '***';
      } else {
        // ensure scalar/array only
        if (is_object($v))      $out[$k] = '[object ' . get_class($v) . ']';
        elseif (is_resource($v))$out[$k] = '[resource]';
        else                    $out[$k] = $v;
      }
    }
    return $out;
  }

  /**
   * Core logger.
   */
  function sfm_log(string $level, string $message, array $context = []): void {
    $level = strtolower($level);
    $record = [
      'ts'     => date('c'),
      'level'  => $level,
      'msg'    => $message,
      'ip'     => $_SERVER['HTTP_CF_CONNECTING_IP']
                  ?? $_SERVER['HTTP_X_FORWARDED_FOR']
                  ?? $_SERVER['REMOTE_ADDR']
                  ?? null,
      'method' => $_SERVER['REQUEST_METHOD'] ?? null,
      'path'   => $_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? null),
      'ua'     => $_SERVER['HTTP_USER_AGENT'] ?? null,
      'extra'  => sfm_log_scrub($context),
    ];

    $line = json_encode($record, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $file = sfm_logger_file();

    // Attempt rotation then append with flock
    sfm_log_rotate($file);

    $fh = @fopen($file, 'ab');
    if ($fh === false) {
      // Fall back to PHP error_log
      @error_log('[SFM] ' . $line);
      return;
    }

    // Try to lock and write
    @flock($fh, LOCK_EX);
    @fwrite($fh, $line);
    @flock($fh, LOCK_UN);
    @fclose($fh);
  }

  // Convenience wrappers
  function sfm_log_info(string $message, array $context = []): void {
    sfm_log('info', $message, $context);
  }
  function sfm_log_warn(string $message, array $context = []): void {
    sfm_log('warn', $message, $context);
  }
  function sfm_log_error(string $message, array $context = []): void {
    sfm_log('error', $message, $context);
  }

  /**
   * Handy helper for HTTP error responses.
   */
  function sfm_log_http_error(int $status, string $message, array $context = []): void {
    sfm_log('error', $message, array_merge(['status' => $status], $context));
  }
}