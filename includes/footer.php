        <footer class="app-footer">
            <span><?= e(APP_NAME) ?> &middot; Vehicle Maintenance &amp; Document Tracker</span>
        </footer>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= url('/assets/js/script.js') ?>"></script>
<?php if (!empty($extraScripts)) { echo $extraScripts; } ?>
</body>
</html>
