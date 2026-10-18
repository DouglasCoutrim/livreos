<?php
// Caminho: /includes/footer.php
declare(strict_types=1);
?>
        </div><!-- /.page-content -->
    </main>
</div><!-- /.app-shell -->

<nav class="bn" aria-label="Navegação">
    <a href="<?= BASE_URL ?>/dashboard.php" class="<?= ($activeModule === 'dashboard') ? 'active' : '' ?>">Início</a>
    <a href="<?= BASE_URL ?>/modules/financeiro/lancamentos.php" class="<?= ($activeModule === 'financeiro') ? 'active' : '' ?>">Financeiro</a>
    <a href="<?= BASE_URL ?>/modules/vendas/pedidos.php" class="<?= ($activeModule === 'vendas') ? 'active' : '' ?>">Vendas</a>
    <a href="<?= BASE_URL ?>/modules/estoque/estoques.php" class="<?= ($activeModule === 'estoque') ? 'active' : '' ?>">Estoque</a>
    <button type="button" id="btnMobileMenu">Menu</button>
</nav>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/app.js"></script>
</body>
</html>