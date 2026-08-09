<?php
// html/includes/header.php
require_once __DIR__ . '/auth.php';
requireLogin();
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - Gestión de Subtítulos</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 4 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --bg-color: #121212;
            --card-bg: rgba(255, 255, 255, 0.05);
            --card-border: rgba(255, 255, 255, 0.1);
            --text-color: #e0e0e0;
        }
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .navbar-custom {
            background: rgba(18, 18, 18, 0.8);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--card-border);
        }
        .navbar-brand {
            font-weight: 800;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: 1px;
        }
        .nav-link {
            font-weight: 600;
            color: var(--text-color) !important;
            transition: color 0.3s ease;
        }
        .nav-link:hover, .nav-link.active {
            color: #4facfe !important;
        }
        .main-content {
            flex: 1;
            padding: 2rem 0;
            animation: fadeIn 0.5s ease-in-out;
        }
        .glass-card {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border: 1px solid var(--card-border);
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
            transition: transform 0.3s ease;
        }
        .glass-card:hover {
            transform: translateY(-5px);
            border-color: rgba(79, 172, 254, 0.5);
        }
        .btn-gradient {
            background: var(--primary-gradient);
            border: none;
            color: #fff;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(79, 172, 254, 0.4);
            color: #fff;
        }
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        ::-webkit-scrollbar-track {
            background: var(--bg-color);
        }
        ::-webkit-scrollbar-thumb {
            background: #4facfe;
            border-radius: 4px;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        /* Task indicator */
        #tasks-indicator { cursor: pointer; transition: opacity 0.3s ease; }
        #tasks-indicator:hover { opacity: 0.8; }
        .task-row { border-bottom: 1px solid rgba(255,255,255,0.07); padding: 0.5rem 0; }
        .task-row:last-child { border-bottom: none; }
        .badge-running { animation: pulse-badge 1.5s infinite; }
        @keyframes pulse-badge {
            0%, 100% { box-shadow: 0 0 0 0 rgba(79,172,254,0.4); }
            50% { box-shadow: 0 0 0 6px rgba(79,172,254,0); }
        }
        .offcanvas-dark { background: rgba(18,18,18,0.98); backdrop-filter: blur(20px); border-left: 1px solid rgba(255,255,255,0.1); }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-custom sticky-top">
    <div class="container">
        <a class="navbar-brand" href="index.php"><i class="fa fa-language"></i> <?= APP_NAME ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link <?= $current_page == 'index.php' ? 'active' : '' ?>" href="index.php">
                        <i class="fa fa-dashboard"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page == 'movies.php' ? 'active' : '' ?>" href="movies.php">
                        <i class="fa fa-film"></i> Películas
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $current_page == 'series.php' ? 'active' : '' ?>" href="series.php">
                        <i class="fa fa-tv"></i> Series
                    </a>
                </li>
            </ul>
            <ul class="navbar-nav">
                <!-- Task Indicator -->
                <li class="nav-item d-flex align-items-center me-3">
                    <div id="tasks-indicator" title="Tareas en background"
                         data-bs-toggle="offcanvas" data-bs-target="#taskPanel"
                         class="d-flex align-items-center gap-2">
                        <div id="spinner-task" class="spinner-border spinner-border-sm text-info d-none" role="status"></div>
                        <i id="idle-task" class="fa fa-check-circle text-success" style="font-size:1.1rem"></i>
                        <span id="tasks-badge" class="badge rounded-pill d-none"
                              style="background:linear-gradient(135deg,#4facfe,#00f2fe);color:#111">0</span>
                    </div>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="fa fa-user-circle"></i> <?= htmlspecialchars($_SESSION['username'] ?? 'Usuario') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
                        <li><a class="dropdown-item" href="settings.php"><i class="fa fa-cogs"></i> Configuración</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php"><i class="fa fa-sign-out"></i> Salir</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- ===== OFFCANVAS: Panel de Tareas ===== -->
<div class="offcanvas offcanvas-end offcanvas-dark text-light" tabindex="-1" id="taskPanel" aria-labelledby="taskPanelLabel">
    <div class="offcanvas-header border-bottom border-secondary">
        <h5 class="offcanvas-title" id="taskPanelLabel">
            <i class="fa fa-tasks me-2 text-info"></i>Tareas en Background
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body px-3" id="taskPanelBody">
        <div class="text-center text-muted py-4">
            <div class="spinner-border spinner-border-sm text-info" role="status"></div>
            <p class="mt-2 small">Cargando tareas...</p>
        </div>
    </div>
    <div class="offcanvas-footer border-top border-secondary p-3">
        <div class="d-flex gap-2">
            <button id="btnScanNow" class="btn btn-sm btn-gradient flex-grow-1" onclick="triggerScan()">
                <i class="fa fa-refresh me-1"></i>Escanear ahora
            </button>
        </div>
        <div id="nextScanInfo" class="small text-muted mt-2 text-center"></div>
    </div>
</div>
<!-- ============================= -->

<div class="main-content">
    <div class="container">

<script>
// ===== TASK POLLING =====
const POLL_INTERVAL = 15000; // 15 segundos
let taskPanelLoaded = false;

function formatTaskStatus(status, progress) {
    // Para traducciones activas con progreso conocido, mostrar "N / M partes"
    if (progress && progress.total_chunks > 0 && (status === 'running' || status === 'pending')) {
        if (status === 'running') {
            return `<span class="badge bg-info badge-running"><i class="fa fa-refresh fa-spin me-1"></i>${progress.completed_chunks} / ${progress.total_chunks} partes</span>`;
        }
        return `<span class="badge bg-secondary"><i class="fa fa-clock-o me-1"></i>En cola</span>`;
    }
    const map = {
        'pending':  '<span class="badge bg-secondary">Pendiente</span>',
        'running':  '<span class="badge bg-info badge-running">Ejecutando</span>',
        'done':     '<span class="badge bg-success">Completada</span>',
        'error':    '<span class="badge bg-danger">Error</span>'
    };
    return map[status] || `<span class="badge bg-secondary">${status}</span>`;
}

function progressBarHtml(progress) {
    if (!progress || progress.total_chunks <= 0) return '';
    const pct = Math.min(100, Math.round((progress.completed_chunks / progress.total_chunks) * 100));
    return `<div class="progress mt-1" style="height:4px;width:120px;"><div class="progress-bar bg-info" style="width:${pct}%"></div></div>`;
}

function formatTaskType(type) {
    const map = {
        'scan_media': '<i class="fa fa-search me-1"></i>Escaneo de Medios',
        'translate':  '<i class="fa fa-language me-1"></i>Traducción',
        'rename_subtitle': '<i class="fa fa-tag me-1"></i>Renombrado'
    };
    return map[type] || type;
}

function timeAgo(dateStr) {
    if (!dateStr) return '';
    const diff = Math.floor((Date.now() - new Date(dateStr + ' UTC').getTime()) / 1000);
    if (diff < 60) return `hace ${diff}s`;
    if (diff < 3600) return `hace ${Math.floor(diff/60)}m`;
    if (diff < 86400) return `hace ${Math.floor(diff/3600)}h`;
    return `hace ${Math.floor(diff/86400)}d`;
}

function renderTaskPanel(data) {
    const tasks = data.tasks || [];
    let html = '';

    if (tasks.length === 0) {
        html = '<p class="text-muted text-center small py-3">No hay tareas en las últimas 24 horas.</p>';
    } else {
        tasks.forEach(t => {
            html += `<div class="task-row">
                <div class="d-flex justify-content-between align-items-start">
                    <span class="small">${formatTaskType(t.type)}</span>
                    ${formatTaskStatus(t.status, t.progress)}
                </div>
                ${t.progress && t.status === 'running' ? progressBarHtml(t.progress) : ''}
                ${t.result ? `<div class="text-muted" style="font-size:0.72rem;margin-top:2px">${t.result}</div>` : ''}
                <div class="text-muted" style="font-size:0.68rem;margin-top:2px">${timeAgo(t.created_at)}</div>
            </div>`;
        });
    }

    document.getElementById('taskPanelBody').innerHTML = html;

    // Próxima ejecución
    const nextEl = document.getElementById('nextScanInfo');
    if (data.next_scan) {
        const nextDate = new Date(data.next_scan + ' UTC');
        nextEl.innerHTML = `<i class="fa fa-clock-o me-1"></i>Próximo escaneo: ${nextDate.toLocaleTimeString('es', {hour:'2-digit',minute:'2-digit'})} (cada ${data.interval_minutes} min)`;
    } else if (data.last_scan === null) {
        nextEl.innerHTML = '<i class="fa fa-exclamation-triangle me-1 text-warning"></i>Sin escaneo previo. Ejecute manualmente.';
    } else {
        nextEl.textContent = '';
    }
}

function updateTaskIndicator(count) {
    const spinner = document.getElementById('spinner-task');
    const idle    = document.getElementById('idle-task');
    const badge   = document.getElementById('tasks-badge');

    if (count > 0) {
        spinner.classList.remove('d-none');
        idle.classList.add('d-none');
        badge.textContent = count;
        badge.classList.remove('d-none');
    } else {
        spinner.classList.add('d-none');
        idle.classList.remove('d-none');
        badge.classList.add('d-none');
    }
}

async function pollTasks() {
    try {
        const res = await fetch('ajax_tasks.php?action=running_count');
        const data = await res.json();
        updateTaskIndicator(data.count || 0);
    } catch(e) {}
}

async function loadTaskPanel() {
    try {
        const res = await fetch('ajax_tasks.php?action=status');
        const data = await res.json();
        renderTaskPanel(data);
        updateTaskIndicator((data.tasks || []).filter(t => ['running','pending'].includes(t.status)).length);
    } catch(e) {
        document.getElementById('taskPanelBody').innerHTML = '<p class="text-danger small">Error al cargar tareas.</p>';
    }
}

async function triggerScan() {
    const btn = document.getElementById('btnScanNow');
    btn.disabled = true;
    btn.innerHTML = '<div class="spinner-border spinner-border-sm me-1"></div>Iniciando...';
    try {
        const res = await fetch('ajax_tasks.php?action=trigger');
        const data = await res.json();
        if (data.success) {
            setTimeout(() => loadTaskPanel(), 1500);
        } else {
            alert(data.message || 'Error al iniciar el escaneo.');
        }
    } catch(e) {
        alert('Error de conexión.');
    }
    setTimeout(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-refresh me-1"></i>Escanear ahora';
    }, 2000);
}

// Cargar el panel al abrirlo
document.addEventListener('show.bs.offcanvas', function(e) {
    if (e.target.id === 'taskPanel') loadTaskPanel();
});

// Polling periódico del contador
pollTasks();
setInterval(pollTasks, POLL_INTERVAL);

// Recargar el contenido del panel mientras esté abierto (para ver el avance)
setInterval(() => {
    const panel = document.getElementById('taskPanel');
    if (panel && panel.classList.contains('show')) {
        loadTaskPanel();
    }
}, POLL_INTERVAL);
</script>

