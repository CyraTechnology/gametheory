
<?php
// Advanced Database Connection with Error Handling
class Database {
    private $host = "localhost";
    private $dbname = "u287505330_gametheory";
    private $username = "u287505330_gametheory";
    private $password = "1Xus23tztv@game";
    private $conn;
    private static $instance = null;

    public function __construct() {
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->dbname . ";charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => true
                ]
            );
            
            // Set timezone for database
            $this->conn->exec("SET time_zone = '+00:00'");
            
        } catch(PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Connection failed: " . $e->getMessage());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }

    // Advanced Query Methods
    public function executeQuery($sql, $params = []) {
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch(PDOException $e) {
            error_log("Query Error: " . $e->getMessage() . " - SQL: " . $sql);
            return false;
        }
    }

    public function fetchAll($sql, $params = []) {
        $stmt = $this->executeQuery($sql, $params);
        return $stmt ? $stmt->fetchAll() : [];
    }

    public function fetchOne($sql, $params = []) {
        $stmt = $this->executeQuery($sql, $params);
        return $stmt ? $stmt->fetch() : false;
    }

    public function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        $sql = "INSERT INTO $table ($columns) VALUES ($placeholders)";
        
        $stmt = $this->executeQuery($sql, $data);
        return $stmt ? $this->conn->lastInsertId() : false;
    }

    public function update($table, $data, $where, $whereParams = []) {
        $setParts = [];
        foreach ($data as $key => $value) {
            $setParts[] = "$key = :$key";
        }
        $setClause = implode(', ', $setParts);
        
        $sql = "UPDATE $table SET $setClause WHERE $where";
        $params = array_merge($data, $whereParams);
        
        $stmt = $this->executeQuery($sql, $params);
        return $stmt ? $stmt->rowCount() : false;
    }

    // Transaction Support
    public function beginTransaction() {
        return $this->conn->beginTransaction();
    }

    public function commit() {
        return $this->conn->commit();
    }

    public function rollback() {
        return $this->conn->rollback();
    }

    // Game Theory Specific Methods
    public function getCompetitorAnalysis($productId) {
        $sql = "SELECT 
                    cp.competitor_name,
                    cp.price,
                    cp.stock,
                    cp.demand_level,
                    cp.price_trend,
                    cp.last_updated,
                    c.strategy as competitor_strategy,
                    c.market_share,
                    TIMESTAMPDIFF(MINUTE, cp.last_updated, NOW()) as minutes_since_update
                FROM competitor_prices cp
                LEFT JOIN companies c ON cp.company_id = c.id
                WHERE cp.product_id = ?
                ORDER BY cp.price ASC, cp.last_updated DESC";
        
        return $this->fetchAll($sql, [$productId]);
    }

    public function getMarketPosition($companyId, $productId) {
        $sql = "SELECT 
                    (SELECT COUNT(*) + 1 
                     FROM competitor_prices cp2 
                     WHERE cp2.product_id = cp.product_id 
                     AND cp2.price < cp.price) as price_rank,
                    AVG(cp2.price) as market_avg_price,
                    MIN(cp2.price) as market_min_price,
                    MAX(cp2.price) as market_max_price
                FROM competitor_prices cp
                LEFT JOIN competitor_prices cp2 ON cp.product_id = cp2.product_id
                WHERE cp.company_id = ? AND cp.product_id = ?
                GROUP BY cp.id";
        
        return $this->fetchOne($sql, [$companyId, $productId]);
    }

    public function calculateOptimalPrice($productId, $companyId) {
        // Advanced price optimization algorithm
        $sql = "SELECT 
                    p.base_cost,
                    p.current_price,
                    p.demand_elasticity,
                    p.competition_sensitivity,
                    AVG(cp.price) as avg_competitor_price,
                    MIN(cp.price) as min_competitor_price,
                    MAX(cp.price) as max_competitor_price,
                    COUNT(cp.id) as competitor_count,
                    COALESCE(SUM(dl.purchases) / NULLIF(SUM(dl.views), 0), 0) * 100 as avg_conversion_rate,
                    COALESCE(AVG(dl.purchases), 0) as avg_daily_sales
                FROM products p
                LEFT JOIN competitor_prices cp ON p.id = cp.product_id 
                    AND cp.company_id != ?
                LEFT JOIN demand_logs dl ON p.id = dl.product_id 
                    AND dl.log_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                WHERE p.id = ?";
        
        $data = $this->fetchOne($sql, [$companyId, $productId]);
        
        if (!$data) return null;
        
        // Game Theory Price Calculation
        $baseCost = $data['base_cost'];
        $competitorAvg = $data['avg_competitor_price'] ?: $data['current_price'];
        $elasticity = $data['demand_elasticity'];
        $conversionRate = $data['avg_conversion_rate'] / 100;
        
        // Calculate optimal price using Cournot/Bertrand competition models
        $targetMargin = 0.20; // 20% minimum margin
        
        // Strategy 1: Follow market average with elasticity adjustment
        $price1 = $competitorAvg * (1 + (($conversionRate - 0.1) * 0.5));
        
        // Strategy 2: Cost-plus with market conditions
        $price2 = $baseCost * (1 + $targetMargin) * 
                  (1 + ($data['competitor_count'] > 3 ? -0.1 : 0.05));
        
        // Strategy 3: Competitive undercutting if low conversion
        $price3 = $data['min_competitor_price'] * 0.97;
        
        // Weighted average based on strategy
        $weights = [0.4, 0.3, 0.3]; // More weight to market following
        $optimalPrice = ($price1 * $weights[0]) + ($price2 * $weights[1]) + ($price3 * $weights[2]);
        
        // Ensure minimum margin
        $minPrice = $baseCost * 1.1;
        $optimalPrice = max($optimalPrice, $minPrice);
        
        // Round to .99
        $optimalPrice = floor($optimalPrice) + 0.99;
        
        return round($optimalPrice, 2);
    }

    public function logPriceChange($productId, $companyId, $oldPrice, $newPrice, $reason = 'strategy') {
        $marketPosition = $this->getMarketPosition($companyId, $productId);
        
        $data = [
            'product_id' => $productId,
            'company_id' => $companyId,
            'old_price' => $oldPrice,
            'new_price' => $newPrice,
            'change_reason' => $reason,
            'profit_margin_before' => ($oldPrice - $this->getProductCost($productId)) / $oldPrice * 100,
            'profit_margin_after' => ($newPrice - $this->getProductCost($productId)) / $newPrice * 100,
            'market_position_before' => $marketPosition['price_rank'] ?? null,
            'competitor_avg_before' => $marketPosition['market_avg_price'] ?? null,
            'changed_at' => date('Y-m-d H:i:s')
        ];
        
        return $this->insert('price_history', $data);
    }

    private function getProductCost($productId) {
        $sql = "SELECT base_cost FROM products WHERE id = ?";
        $result = $this->fetchOne($sql, [$productId]);
        return $result ? $result['base_cost'] : 0;
    }
}

// Global database instance
$db = Database::getInstance();
$conn = $db->getConnection();

// Session and Authentication Helper Functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isCompanyManager() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'company_manager';
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: /customer/login.php");
        exit();
    }
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header("Location: /");
        exit();
    }
}

function requireCompanyManager() {
    requireLogin();
    if (!isCompanyManager()) {
        header("Location: /");
        exit();
    }
}

// Price formatting helper
function formatPrice($price) {
    return '$' . number_format($price, 2);
}

// Demand level color coding
function getDemandColor($level) {
    $colors = [
        'low' => 'danger',
        'medium' => 'warning',
        'high' => 'success',
        'critical' => 'primary'
    ];
    return $colors[$level] ?? 'secondary';
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>