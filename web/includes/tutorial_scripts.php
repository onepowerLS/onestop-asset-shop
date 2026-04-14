<?php
/**
 * Interactive tutorial (Fleet-style): AM_TUTORIAL config + overlay script + styles.
 * Include once before </body> on logged-in pages that use the standard shell.
 */
declare(strict_types=1);

$amTutorialLoggedIn = function_exists('is_logged_in') && is_logged_in();
if (!$amTutorialLoggedIn && empty($_SESSION['firebase_id_token'] ?? null)) {
    return;
}

require_once __DIR__ . '/../config/tutorial_interactive.php';
$amTutorialCfg = am_tutorial_client_config();
?>
<style>
.am-tutorial-overlay { position: fixed; inset: 0; z-index: 10050; pointer-events: auto; }
.am-tutorial-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.55); backdrop-filter: blur(1px); }
.am-tutorial-highlight {
  position: fixed; z-index: 10051; border-radius: 10px; border: 2px solid #3b82f6;
  box-shadow: 0 0 0 9999px rgba(0,0,0,0.5);
  pointer-events: none;
}
.am-tutorial-panel-wrap {
  position: fixed; bottom: 0; left: 0; right: 0; z-index: 10052;
  padding: 1rem 1rem 1.25rem; pointer-events: none;
}
.am-tutorial-panel { max-width: 32rem; margin: 0 auto; pointer-events: auto; padding: 1rem 1.1rem; }
</style>
<script>window.AM_TUTORIAL = <?php
    $amTutorialJson = json_encode(
        $amTutorialCfg,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    echo $amTutorialJson !== false ? $amTutorialJson : '{}';
?>;</script>
<script src="<?php echo htmlspecialchars(base_url('assets/js/am-tutorial.js')); ?>" defer></script>
