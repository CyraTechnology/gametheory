<?php
session_start();
require '../config/db.php';

if(!isset($_SESSION['user_id'])){
    header("Location: login.php");
    exit;
}

$user = $_SESSION['user_id'];

/* ======================
WALLET BALANCE
====================== */

$stmt = $conn->prepare("
SELECT balance 
FROM wallets 
WHERE user_id = ?
");

$stmt->execute([$user]);
$balance = $stmt->fetchColumn() ?? 0;

/* ======================
TRANSACTIONS
====================== */

$transactions = $conn->prepare("
SELECT 
    wt.*,
    po.unit_price,
    po.shipping_cost,
    po.total_price,
    p.name AS product_name
FROM wallet_transactions wt
LEFT JOIN purchase_orders po ON po.order_number = wt.order_number
LEFT JOIN products p ON p.id = po.product_id
WHERE wt.user_id = ?
ORDER BY wt.created_at DESC
");

$transactions->execute([$user]);

include "../layout/header.php";
include "../layout/customer_sidebar.php";
?>

<!-- Add Mobile Optimized Meta Tags -->
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#4e73df">

<style>
    /* ======================
    WALLET STYLES - Adapted for Sidebar
    ====================== */
    :root {
        --primary-color: #4e73df;
        --success-color: #1cc88a;
        --danger-color: #e74a3b;
        --warning-color: #f6c23e;
        --dark-color: #5a5c69;
        --bg-light: #f8f9fc;
        --sidebar-width: 250px;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Roboto', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        background: var(--bg-light);
        color: #333;
        line-height: 1.5;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
        overflow-x: hidden;
    }

    /* Main content area - works with sidebar */
    .main-content {
        margin-left: var(--sidebar-width);
        padding: 20px;
        transition: margin-left 0.3s ease;
        min-height: 100vh;
        background: var(--bg-light);
    }

    /* When sidebar is collapsed on desktop */
    body.sidebar-collapsed .main-content {
        margin-left: 70px;
    }

    /* ======================
    MOBILE HEADER WITH MENU BUTTON
    ====================== */
    .mobile-header {
        display: none;
        align-items: center;
        justify-content: space-between;
        padding: 12px 16px;
        background: white;
        position: sticky;
        top: 0;
        z-index: 100;
        box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        border-bottom: 1px solid #edf2f7;
    }

    .menu-toggle {
        width: 40px;
        height: 40px;
        background: var(--primary-color);
        border: none;
        border-radius: 8px;
        color: white;
        font-size: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s ease;
        -webkit-tap-highlight-color: transparent;
    }

    .menu-toggle:active {
        transform: scale(0.95);
        background: #3a5fc7;
    }

    .mobile-header h1 {
        font-size: 20px;
        font-weight: 600;
        color: #2c3e50;
        margin: 0;
    }

    .mobile-header-actions {
        display: flex;
        gap: 12px;
    }

    .mobile-header-actions i {
        font-size: 20px;
        color: #95a5a6;
    }

    /* ======================
    PAGE HEADER
    ====================== */
    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .page-header h2 {
        font-size: 28px;
        font-weight: 600;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .page-header h2 i {
        color: var(--primary-color);
        background: rgba(78, 115, 223, 0.1);
        padding: 10px;
        border-radius: 12px;
    }

    .header-actions {
        display: flex;
        gap: 12px;
    }

    /* ======================
    WALLET BALANCE CARD - TOP
    ====================== */
    .wallet-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 24px;
        padding: 24px;
        margin-bottom: 24px;
        color: white;
        box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        position: relative;
        overflow: hidden;
    }

    .wallet-card::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        transform: rotate(45deg);
    }

    .wallet-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
        position: relative;
        z-index: 1;
    }

    .wallet-header i {
        font-size: 32px;
        background: rgba(255,255,255,0.2);
        padding: 12px;
        border-radius: 16px;
    }

    .wallet-header span {
        font-size: 16px;
        opacity: 0.9;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .balance-content {
        position: relative;
        z-index: 1;
    }

    .balance-label {
        font-size: 14px;
        opacity: 0.8;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .balance-amount {
        font-size: 48px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .balance-amount small {
        font-size: 18px;
        font-weight: 400;
        opacity: 0.7;
    }

    /* ======================
    SEARCH AND FILTER SECTION
    ====================== */
    .search-section {
        background: white;
        border-radius: 16px;
        padding: 20px;
        margin-bottom: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }

    .search-container {
        display: flex;
        gap: 12px;
        margin-bottom: 16px;
    }

    .search-input-wrapper {
        flex: 1;
        position: relative;
    }

    .search-input-wrapper i {
        position: absolute;
        left: 16px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 18px;
    }

    .search-input {
        width: 100%;
        padding: 14px 16px 14px 48px;
        border: 1.5px solid #e2e8f0;
        border-radius: 12px;
        font-size: 15px;
        transition: all 0.3s ease;
        background: #f8fafc;
    }

    .search-input:focus {
        outline: none;
        border-color: var(--primary-color);
        background: white;
        box-shadow: 0 0 0 4px rgba(78, 115, 223, 0.1);
    }

    .filter-btn {
        padding: 14px 24px;
        background: #f8fafc;
        border: 1.5px solid #e2e8f0;
        border-radius: 12px;
        color: #4a5568;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .filter-btn:hover {
        background: white;
        border-color: var(--primary-color);
        color: var(--primary-color);
    }

    .filter-btn i {
        font-size: 16px;
    }

    /* Filter Chips */
    .filter-chips {
        display: flex;
        gap: 10px;
        overflow-x: auto;
        padding: 8px 0;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
    }

    .filter-chips::-webkit-scrollbar {
        height: 4px;
    }

    .filter-chips::-webkit-scrollbar-thumb {
        background: #cbd5e0;
        border-radius: 4px;
    }

    .chip {
        padding: 8px 20px;
        background: #f1f5f9;
        border-radius: 30px;
        font-size: 13px;
        font-weight: 500;
        color: #4a5568;
        white-space: nowrap;
        cursor: pointer;
        transition: all 0.2s ease;
        border: 1px solid transparent;
    }

    .chip:hover {
        background: #e2e8f0;
    }

    .chip.active {
        background: var(--primary-color);
        color: white;
    }

    .chip:active {
        transform: scale(0.95);
    }

    /* ======================
    TRANSACTION HISTORY CARD
    ====================== */
    .transactions-card {
        background: white;
        border-radius: 20px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }

    .card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
        padding-bottom: 16px;
        border-bottom: 1px solid #edf2f7;
    }

    .card-header h5 {
        font-size: 18px;
        font-weight: 600;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0;
    }

    .card-header h5 i {
        color: var(--primary-color);
    }

    .transaction-count {
        background: #f1f5f9;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 13px;
        color: #4a5568;
        font-weight: 500;
    }

    /* Transaction Items Container */
    .transactions-container {
        display: flex;
        flex-direction: column;
        gap: 12px;
        max-height: 600px;
        overflow-y: auto;
        padding-right: 4px;
    }

    .transactions-container::-webkit-scrollbar {
        width: 6px;
    }

    .transactions-container::-webkit-scrollbar-track {
        background: #f1f5f9;
        border-radius: 10px;
    }

    .transactions-container::-webkit-scrollbar-thumb {
        background: #cbd5e0;
        border-radius: 10px;
    }

    .transactions-container::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

    /* Transaction Item */
    .transaction-item {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 16px;
        background: #f8fafc;
        border-radius: 16px;
        transition: all 0.3s ease;
        border: 1px solid transparent;
    }

    .transaction-item:hover {
        background: white;
        border-color: var(--primary-color);
        box-shadow: 0 4px 12px rgba(78, 115, 223, 0.1);
    }

    .transaction-item.hidden {
        display: none;
    }

    /* Transaction Icon */
    .transaction-icon {
        width: 52px;
        height: 52px;
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        flex-shrink: 0;
    }

    .icon-success {
        background: rgba(28, 200, 138, 0.1);
        color: var(--success-color);
    }

    .icon-danger {
        background: rgba(231, 74, 59, 0.1);
        color: var(--danger-color);
    }

    .icon-warning {
        background: rgba(246, 194, 62, 0.1);
        color: var(--warning-color);
    }

    .icon-primary {
        background: rgba(78, 115, 223, 0.1);
        color: var(--primary-color);
    }

    /* Transaction Details */
    .transaction-details {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .transaction-row {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }

    .transaction-type-badge {
        padding: 6px 14px;
        border-radius: 30px;
        font-size: 13px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        min-width: 100px;
        text-align: center;
    }

    .badge-success {
        background: rgba(28, 200, 138, 0.15);
        color: var(--success-color);
        border: 1px solid rgba(28, 200, 138, 0.3);
    }

    .badge-danger {
        background: rgba(231, 74, 59, 0.15);
        color: var(--danger-color);
        border: 1px solid rgba(231, 74, 59, 0.3);
    }

    .badge-warning {
        background: rgba(246, 194, 62, 0.15);
        color: #b76e00;
        border: 1px solid rgba(246, 194, 62, 0.3);
    }

    .badge-primary {
        background: rgba(78, 115, 223, 0.15);
        color: var(--primary-color);
        border: 1px solid rgba(78, 115, 223, 0.3);
    }

    .transaction-amount {
        font-weight: 700;
        font-size: 18px;
    }

    .amount-positive {
        color: var(--success-color);
    }

    .amount-negative {
        color: var(--danger-color);
    }

    .transaction-description {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #64748b;
        font-size: 14px;
        flex: 1;
    }

    .transaction-description i {
        color: var(--primary-color);
        font-size: 14px;
        width: 18px;
    }

    .transaction-description span {
        word-break: break-word;
    }

    .transaction-date {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #94a3b8;
        font-size: 13px;
        white-space: nowrap;
    }

    .transaction-date i {
        font-size: 13px;
        color: var(--primary-color);
    }

    .product-name-tag {
        background: #e2e8f0;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        color: #475569;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .product-name-tag i {
        font-size: 11px;
        color: var(--primary-color);
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
    }

    .empty-state i {
        font-size: 80px;
        color: #cbd5e0;
        margin-bottom: 20px;
    }

    .empty-state h4 {
        font-size: 20px;
        color: #4a5568;
        margin-bottom: 8px;
    }

    .empty-state p {
        color: #94a3b8;
        font-size: 14px;
        margin-bottom: 24px;
    }

    .empty-state button {
        background: var(--primary-color);
        color: white;
        border: none;
        padding: 14px 30px;
        border-radius: 30px;
        font-weight: 600;
        font-size: 14px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .empty-state button:hover {
        background: #3a5fc7;
        transform: translateY(-2px);
    }

    /* No Results State */
    .no-results {
        text-align: center;
        padding: 40px 20px;
        color: #94a3b8;
    }

    .no-results i {
        font-size: 48px;
        margin-bottom: 16px;
        opacity: 0.5;
    }

    .no-results p {
        font-size: 16px;
        font-weight: 500;
    }

    /* ======================
    MOBILE RESPONSIVE
    ====================== */
    @media screen and (max-width: 768px) {
        .mobile-header {
            display: flex;
        }

        .main-content {
            margin-left: 0 !important;
            padding: 16px;
            padding-bottom: 80px;
        }

        .page-header {
            margin-top: 0;
        }

        .page-header h2 {
            font-size: 24px;
        }

        .page-header h2 i {
            font-size: 22px;
            padding: 8px;
        }

        .balance-amount {
            font-size: 36px;
        }

        .search-container {
            flex-direction: column;
        }

        .filter-btn {
            width: 100%;
            justify-content: center;
        }

        .transaction-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 12px;
        }

        .transaction-icon {
            width: 44px;
            height: 44px;
            font-size: 18px;
        }

        .transaction-row {
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
            width: 100%;
        }

        .transaction-type-badge {
            width: 100%;
        }

        .transaction-amount {
            align-self: flex-end;
        }

        .transaction-description {
            width: 100%;
        }

        .transaction-date {
            width: 100%;
            justify-content: flex-start;
        }
    }

    /* Small Mobile */
    @media screen and (max-width: 480px) {
        .main-content {
            padding: 12px;
        }

        .wallet-card {
            padding: 20px;
        }

        .balance-amount {
            font-size: 28px;
        }

        .balance-amount small {
            font-size: 14px;
        }

        .transaction-item {
            padding: 12px;
        }

        .transaction-type-badge {
            font-size: 12px;
            padding: 4px 12px;
        }

        .transaction-amount {
            font-size: 16px;
        }

        .transaction-description {
            font-size: 13px;
        }

        .transaction-date {
            font-size: 12px;
        }
    }
</style>

<!-- Mobile Header with Menu Toggle -->
<div class="mobile-header">
    <button class="menu-toggle" id="mobileMenuToggle">
        <i class="fa fa-bars"></i>
    </button>
    <h1>My Wallet</h1>
    <div class="mobile-header-actions">
        <i class="fa fa-qrcode"></i>
        <i class="fa fa-bell"></i>
    </div>
</div>

<!-- Main Content Area -->
<div class="main-content" id="mainContent">
    <!-- Page Header -->
    <div class="page-header">
        <h2>
            <i class="fa fa-wallet"></i> 
            My Wallet
        </h2>
        <div class="header-actions">
            <!-- Empty for now -->
        </div>
    </div>

    <!-- Wallet Balance Card - TOP -->
    <div class="wallet-card">
        <div class="wallet-header">
            <i class="fa fa-google-wallet"></i>
            <span>Total Balance</span>
        </div>
        <div class="balance-content">
            <div class="balance-label">
                <i class="fa fa-rupee"></i>
                Available Balance
            </div>
            <div class="balance-amount">
                ₹<?= number_format($balance) ?>
                <small>.00</small>
            </div>
        </div>
    </div>

    <!-- Search and Filter Section -->
    <div class="search-section">
        <div class="search-container">
            <div class="search-input-wrapper">
                <i class="fa fa-search"></i>
                <input type="text" class="search-input" id="searchInput" placeholder="Search by description or product...">
            </div>
            <button class="filter-btn" id="filterBtn">
                <i class="fa fa-filter"></i>
                <span>Filter</span>
            </button>
        </div>

        <!-- Filter Chips -->
        <div class="filter-chips" id="filterChips">
            <span class="chip active" data-filter="all">All</span>
            <span class="chip" data-filter="price_drop">Price Drops</span>
            <span class="chip" data-filter="credit">Credits</span>
            <span class="chip" data-filter="purchase">Purchases</span>
            <span class="chip" data-filter="debit">Debits</span>
        </div>
    </div>

    <!-- Transaction History -->
    <div class="transactions-card">
        <div class="card-header">
            <h5>
                <i class="fa fa-history"></i>
                Transaction History
            </h5>
            <span class="transaction-count" id="transactionCount">
                <?= $transactions->rowCount() ?> transactions
            </span>
        </div>

        <!-- Transactions Container -->
        <div class="transactions-container" id="transactionsContainer">
            <?php if($transactions->rowCount() == 0): ?>
                <div class="empty-state">
                    <i class="fa fa-wallet"></i>
                    <h4>No transactions yet</h4>
                    <p>Your transaction history will appear here</p>
                    <button onclick="window.location.href='product_list.php'">
                        <i class="fa fa-shopping-cart"></i> Start Shopping
                    </button>
                </div>
            <?php else: ?>
                <?php foreach($transactions as $t): 
                    /* ======================
                    COLOR & ICON LOGIC
                    ====================== */
                    $badgeClass = "badge-primary";
                    $iconClass = "icon-primary";
                    $icon = "fa-exchange";
                    $typeLabel = ucfirst($t['type']);
                    $amountClass = "amount-negative";
                    
                    if($t['type'] == "price_drop") {
                        $badgeClass = "badge-success";
                        $iconClass = "icon-success";
                        $icon = "fa-arrow-down";
                        $typeLabel = "Price Drop Refund";
                        $amountClass = "amount-positive";
                    }
                    elseif($t['type'] == "credit") {
                        $badgeClass = "badge-success";
                        $iconClass = "icon-success";
                        $icon = "fa-plus";
                        $typeLabel = "Credit";
                        $amountClass = "amount-positive";
                    }
                    elseif($t['type'] == "purchase") {
                        $badgeClass = "badge-danger";
                        $iconClass = "icon-danger";
                        $icon = "fa-shopping-cart";
                        $typeLabel = "Purchase";
                        $amountClass = "amount-negative";
                    }
                    elseif($t['type'] == "debit") {
                        $badgeClass = "badge-danger";
                        $iconClass = "icon-danger";
                        $icon = "fa-arrow-up";
                        $typeLabel = "Debit";
                        $amountClass = "amount-negative";
                    }
                    
                    $amount = $t['total_price'] ?? $t['amount'];
                    $isCredit = ($t['type'] == 'credit' || $t['type'] == 'price_drop');
                    
                    // Build searchable text
                    $searchText = strtolower($t['description'] . ' ' . ($t['product_name'] ?? '') . ' ' . $t['type']);
                ?>
                <div class="transaction-item" 
                     data-type="<?= $t['type'] ?>"
                     data-search="<?= htmlspecialchars($searchText) ?>"
                     data-amount="<?= $amount ?>"
                     data-date="<?= $t['created_at'] ?>">
                    
                    <div class="transaction-icon <?= $iconClass ?>">
                        <i class="fa <?= $icon ?>"></i>
                    </div>
                    
                    <div class="transaction-details">
                        <!-- Row 1: Type and Amount -->
                        <div class="transaction-row">
                            <span class="transaction-type-badge <?= $badgeClass ?>">
                                <?= $typeLabel ?>
                            </span>
                            <span class="transaction-amount <?= $amountClass ?>">
                                <?= $isCredit ? '+' : '-' ?> ₹<?= number_format($amount) ?>
                            </span>
                        </div>
                        
                        <!-- Row 2: Description -->
                        <div class="transaction-description">
                            <i class="fa fa-info-circle"></i>
                            <span><?= htmlspecialchars($t['description'] ?? ($isCredit ? 'Money credited to wallet' : 'Money debited from wallet')) ?></span>
                        </div>
                        
                        <!-- Row 3: Date and Product -->
                        <div class="transaction-row" style="justify-content: space-between;">
                            <div class="transaction-date">
                                <i class="fa fa-calendar"></i>
                                <?= date("d M Y, h:i A", strtotime($t['created_at'])) ?>
                            </div>
                            
                            <?php if($t['product_name']): ?>
                                <div class="product-name-tag">
                                    <i class="fa fa-tag"></i>
                                    <?= htmlspecialchars(substr($t['product_name'], 0, 30)) ?>
                                    <?= strlen($t['product_name']) > 30 ? '...' : '' ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Mobile Touch Optimizations -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Mobile menu toggle
    const menuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.querySelector('.sidebar');
    
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            sidebar.classList.toggle('active');
            
            let overlay = document.querySelector('.sidebar-overlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.className = 'sidebar-overlay';
                document.body.appendChild(overlay);
                
                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                    setTimeout(() => overlay.remove(), 300);
                });
            }
            
            if (sidebar.classList.contains('active')) {
                overlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            } else {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
                setTimeout(() => overlay.remove(), 300);
            }
        });
    }

    // Search functionality
    const searchInput = document.getElementById('searchInput');
    const transactions = document.querySelectorAll('.transaction-item');
    const transactionCount = document.getElementById('transactionCount');
    const noResults = document.createElement('div');
    noResults.className = 'no-results';
    noResults.innerHTML = '<i class="fa fa-search"></i><p>No transactions found</p>';
    
    function filterTransactions() {
        const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
        const activeFilter = document.querySelector('.chip.active')?.dataset.filter || 'all';
        let visibleCount = 0;
        
        transactions.forEach(transaction => {
            const searchText = transaction.dataset.search || '';
            const type = transaction.dataset.type;
            
            // Check type filter
            let typeMatch = activeFilter === 'all' || type === activeFilter;
            
            // Check search term
            let searchMatch = searchTerm === '' || searchText.includes(searchTerm);
            
            if (typeMatch && searchMatch) {
                transaction.classList.remove('hidden');
                visibleCount++;
            } else {
                transaction.classList.add('hidden');
            }
        });
        
        // Update count
        if (transactionCount) {
            transactionCount.textContent = visibleCount + ' transactions';
        }
        
        // Show/hide no results message
        const container = document.getElementById('transactionsContainer');
        const existingNoResults = container.querySelector('.no-results');
        
        if (visibleCount === 0 && transactions.length > 0) {
            if (!existingNoResults) {
                container.appendChild(noResults);
            }
        } else {
            if (existingNoResults) {
                existingNoResults.remove();
            }
        }
    }
    
    // Search input event
    if (searchInput) {
        searchInput.addEventListener('input', filterTransactions);
    }
    
    // Filter chips
    const chips = document.querySelectorAll('.chip');
    chips.forEach(chip => {
        chip.addEventListener('click', function() {
            chips.forEach(c => c.classList.remove('active'));
            this.classList.add('active');
            filterTransactions();
        });
    });
    
    // Filter button (shows filter options)
    const filterBtn = document.getElementById('filterBtn');
    if (filterBtn) {
        filterBtn.addEventListener('click', function() {
            // Scroll to filter chips
            document.querySelector('.filter-chips').scrollIntoView({ 
                behavior: 'smooth',
                block: 'start'
            });
        });
    }
    
    // Handle window resize
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (window.innerWidth > 768) {
                if (sidebar) sidebar.classList.remove('active');
                const overlay = document.querySelector('.sidebar-overlay');
                if (overlay) overlay.remove();
                document.body.style.overflow = '';
            }
        }, 250);
    });
});
</script>
