<nav class="navbar navbar-dark bg-dark sticky-top app-navbar">
    <div class="container-fluid">
        <div class="d-flex align-items-center">
            <button class="navbar-toggler d-lg-none me-2" type="button"
                    data-bs-toggle="offcanvas" data-bs-target="#sidebarMenu"
                    aria-controls="sidebarMenu" aria-label="Open menu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <a class="navbar-brand fw-semibold" href="<?= url('/dashboard.php') ?>">
                <i class="bi bi-car-front-fill text-primary me-2"></i>AutoCare <span class="text-primary">Cloud</span>
            </a>
        </div>
        <span class="navbar-text d-none d-md-inline text-secondary small">
            <i class="bi bi-calendar3 me-1"></i><?= e(date('l, d F Y')) ?>
        </span>
    </div>
</nav>
