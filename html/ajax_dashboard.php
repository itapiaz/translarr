<?php
// html/ajax_dashboard.php — Lee episodios faltantes DESDE EL CACHÉ
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once 'includes/auth.php';
requireLogin();
require_once 'config.php';
require_once 'includes/security.php';

header('Content-Type: application/json');

// Rate limiting (60 consultas por minuto)
rateLimitRequire('dashboard', 60, 60);

$seriesId = $_GET['seriesId'] ?? null;

if (!$seriesId) {
    echo json_encode(['status' => 'error', 'message' => 'ID de serie no proporcionado.']);
    exit;
}

try {
    // Leer episodios faltantes desde media_cache (instantáneo, sin API ni disco)
    $stmt = $pdo->prepare("
        SELECT id, title, season, episode
        FROM media_cache
        WHERE series_id = ? AND type = 'episode' AND has_file = 1 AND has_spanish = 0
        ORDER BY season ASC, episode ASC
    ");
    $stmt->execute([$seriesId]);
    $episodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($episodes)) {
        echo json_encode(['status' => 'success', 'missingCount' => 0, 'html' => '']);
        exit;
    }

    // Agrupar por temporada usando la columna season (número real)
    $seasons = [];
    foreach ($episodes as $ep) {
        $s = (int)($ep['season'] ?? 0);
        $seasons[$s][] = $ep;
    }
    ksort($seasons);

    // Generar HTML del acordeón
    $sid = htmlspecialchars($seriesId);
    $html = '<div class="accordion accordion-flush" id="episodesAccordionDash' . $sid . '">';

    foreach ($seasons as $seasonNum => $seasonEpisodes) {
        $label = $seasonNum > 0 ? 'Temporada ' . $seasonNum : 'Especiales';
        $collapseId = 'collapseDashS' . $sid . '_' . $seasonNum;

        $html .= '<div class="accordion-item bg-transparent border-0 border-bottom border-secondary">';
        $html .= '<h2 class="accordion-header">';
        $html .= '<button class="accordion-button collapsed bg-transparent text-light py-2" type="button"'
               . ' data-bs-toggle="collapse" data-bs-target="#' . $collapseId . '">';
        $html .= $label . ' <span class="badge bg-danger ms-2">' . count($seasonEpisodes) . ' faltan</span>';
        $html .= '</button></h2>';
        $html .= '<div id="' . $collapseId . '" class="accordion-collapse collapse">';
        $html .= '<div class="accordion-body p-0"><ul class="list-group list-group-flush">';

        foreach ($seasonEpisodes as $ep) {
            $epNum   = str_pad((int)($ep['episode'] ?? 0), 2, '0', STR_PAD_LEFT);
            $epTitle = htmlspecialchars($ep['title'] ?? 'Episodio ' . $epNum);
            $epId    = htmlspecialchars($ep['id']);

            $html .= '<li class="list-group-item bg-transparent text-light border-secondary d-flex'
                   . ' justify-content-between align-items-center ps-4 py-2 table-row-hover"'
                   . ' style="cursor:pointer;"'
                   . ' onclick="window.location.href=\'subtitles.php?type=series&id=' . $sid . '\'">'; 
            $html .= '<div class="small"><span class="text-info me-2">E' . $epNum . '</span>' . $epTitle . '</div>';
            $html .= '<i class="fa fa-chevron-right text-muted"></i>';
            $html .= '</li>';
        }

        $html .= '</ul></div></div></div>';
    }
    $html .= '</div>';

    echo json_encode([
        'status'       => 'success',
        'missingCount' => count($episodes),
        'html'         => $html
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
