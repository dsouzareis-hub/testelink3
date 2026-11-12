<?php if (current_user()): ?>
        </main>
    </div>
</div>
<?php else: ?>
</main>
<?php endif; ?>
<script src="assets/js/app.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
</body>
</html>
