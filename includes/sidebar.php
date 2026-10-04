<?php
// key => [label, icon, path]
$menuItems = [
    'dashboard' => ['Dashboard',    'bi-speedometer2',   '/dashboard.php'],
    'vehicles'  => ['My Vehicles',  'bi-car-front',      '/vehicles/'],
    'services'  => ['Services',     'bi-tools',          '/services/'],
    'expenses'  => ['Expenses',     'bi-cash-coin',      '/expenses/'],
    'fuel'      => ['Fuel',         'bi-fuel-pump',      '/fuel/'],
    'documents' => ['Documents',    'bi-folder2-open',   '/documents/'],
    'insurance' => ['Insurance',    'bi-shield-check',   '/insurance/'],
    'warranty'  => ['Warranty',     'bi-patch-check',    '/warranty/'],
    'registration' => ['Registration', 'bi-card-checklist', '/registration/'],
    'analytics' => ['Analytics',    'bi-bar-chart-line', '/analytics/'],
];
?>
<aside class="sidebar-col">
    <div class="offcanvas-lg offcanvas-start sidebar" tabindex="-1" id="sidebarMenu" aria-labelledby="sidebarLabel">
        <div class="offcanvas-header d-lg-none">
            <h5 class="offcanvas-title text-white" id="sidebarLabel">
                <i class="bi bi-car-front-fill text-primary me-2"></i>AutoCare Cloud
            </h5>
            <button type="button" class="btn-close btn-close-white"
                    data-bs-dismiss="offcanvas" data-bs-target="#sidebarMenu" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body p-3">
            <div class="sidebar-heading">Menu</div>
            <ul class="nav nav-pills flex-column gap-1 w-100">
                <?php foreach ($menuItems as $key => [$label, $icon, $path]): ?>
                    <li class="nav-item">
                        <a class="nav-link sidebar-link <?= $activePage === $key ? 'active' : '' ?>"
                           href="<?= url($path) ?>">
                            <i class="bi <?= e($icon) ?> me-2"></i><?= e($label) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</aside>
