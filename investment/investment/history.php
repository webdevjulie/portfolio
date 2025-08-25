<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// DB connection
try {
    $pdo = new PDO("mysql:host=localhost;dbname=investment", 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$userId = $_SESSION['user_id'];

// Optimized queries with proper joins
$queries = [
    'investments' => "SELECT * FROM user_investments WHERE user_id = ? ORDER BY created_at DESC",
    
    'payments' => "
        SELECT ph.*, u.fullname AS sender_name, u.email AS sender_email
        FROM payment_history ph
        LEFT JOIN users u ON ph.sender_user_id = u.id
        WHERE ph.user_id = ?
        ORDER BY ph.payment_date DESC",
    
    'withdrawals' => "
        SELECT DISTINCT wh.id, wh.board_id, wh.receiver_id, wh.sender_id, 
               wh.amount, wh.assignment_type, wh.confirmed_at, wh.sender_name,
               b.status as board_status
        FROM withdrawal_history wh
        LEFT JOIN boards b ON wh.board_id = b.id
        WHERE wh.receiver_id = ?
        ORDER BY wh.confirmed_at DESC",
    
    'sent_money' => "
        SELECT ph.*, receiver.fullname AS receiver_name, receiver.email AS receiver_email
        FROM payment_history ph
        LEFT JOIN users receiver ON ph.user_id = receiver.id
        WHERE ph.sender_user_id = ?
        ORDER BY ph.payment_date DESC"
];

// Fetch all data
$data = [];
foreach ($queries as $key => $query) {
    $stmt = $pdo->prepare($query);
    $stmt->execute([$userId]);
    $data[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate totals
$totals = [
    'invested'  => array_sum(array_column($data['investments'], 'investment_amount')),
    'expected'  => array_sum(array_column($data['investments'], 'expected_return')),
    'received'  => array_sum(array_column($data['payments'], 'amount')),
    'received_amount' => array_sum(array_column($data['payments'], 'received_amount')),
    'profit'    => array_sum(array_column($data['payments'], 'profit_amount')),
    'withdrawn' => array_sum(array_column($data['withdrawals'], 'amount')),
    'sent'      => array_sum(array_column($data['sent_money'], 'amount'))
];

// Render table function
function renderTable($data, $type) {
    if (empty($data)) {
        $messages = [
            'payment' => 'No payment history found.',
            'withdrawal' => 'No withdrawal history found.',
            'sent' => 'No send money history found.'
        ];
        return "<div class='alert alert-info'><i class='fas fa-info-circle me-2'></i>{$messages[$type]}</div>";
    }

    $configs = [
        'payment' => [
            'headers' => ['Date', 'Package', 'Amount', 'Profit', 'Status'],
            'fields' => ['payment_date', 'package_name', 'amount', 'profit_amount', 'payment_status']
        ],
        'withdrawal' => [
            'headers' => ['Date', 'Sender', 'Amount', 'Type', 'Status'],
            'fields' => ['confirmed_at', 'sender_name', 'amount', 'assignment_type', 'board_status']
        ],
        'sent' => [
            'headers' => ['Date', 'Receiver', 'Amount', 'Type', 'Status'],
            'fields' => ['payment_date', 'receiver_name', 'amount', 'payment_type', 'payment_status']
        ]
    ];

    $config = $configs[$type];
    $html = "<div class='table-responsive'><table class='table table-striped'><thead><tr>";
    
    foreach ($config['headers'] as $header) {
        $html .= "<th>$header</th>";
    }
    $html .= "<th>Actions</th></tr></thead><tbody>";

    foreach ($data as $item) {
        $html .= "<tr>";
        foreach ($config['fields'] as $field) {
            $value = $item[$field] ?? '';
            if (in_array($field, ['amount', 'profit_amount'])) {
                $value = 'Rs' . number_format($value, 2);
            } elseif (in_array($field, ['payment_date', 'confirmed_at'])) {
                $value = date('M d, Y H:i', strtotime($value));
            } elseif (in_array($field, ['payment_status', 'board_status'])) {
                $value = "<span class='badge bg-" . ($value == 'completed' ? 'success' : 'warning') . "'>" . ucfirst($value) . "</span>";
            }
            $html .= "<td>$value</td>";
        }
        $html .= "<td><button class='btn btn-sm btn-outline-primary' onclick='viewDetails(" . htmlspecialchars(json_encode($item)) . ", \"$type\")'><i class='fas fa-eye'></i> View</button></td></tr>";
    }
    
    return $html . "</tbody></table></div>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Investment History - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%); min-height: 100vh; }
        .main-content { background: white; margin: 20px; border-radius: 15px; padding: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .summary-card { 
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%); 
            color: white; 
            padding: 25px; 
            border-radius: 15px; 
            text-align: center; 
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: none;
        }
        .summary-card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(255, 107, 53, 0.3); }
        .summary-card h3 { font-size: 1.8rem; margin: 10px 0; font-weight: 700; }
        .summary-card p { margin: 0; opacity: 0.9; font-size: 0.9rem; }
        .summary-card i { font-size: 2.5rem; margin-bottom: 15px; opacity: 0.8; }
        
        .nav-tabs { border: none; background: #f8f9fa; border-radius: 10px; padding: 5px; }
        .nav-tabs .nav-link { 
            border: none; 
            background: none; 
            color: #6c757d; 
            border-radius: 8px;
            margin: 0 2px;
            transition: all 0.3s ease;
        }
        .nav-tabs .nav-link.active { 
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%); 
            color: white; 
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.3);
        }
        .nav-tabs .nav-link:hover:not(.active) { background: rgba(255, 107, 53, 0.1); }
        
        .table { background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .table th { 
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%); 
            color: white; 
            border: none; 
            padding: 15px;
            font-weight: 600;
        }
        .table td { padding: 15px; border: none; border-bottom: 1px solid #f1f3f4; }
        .table-striped tbody tr:nth-of-type(odd) { background-color: #fef9f6; }
        
        .btn-outline-primary { 
            border-color: #ff6b35; 
            color: #ff6b35; 
            transition: all 0.3s ease;
        }
        .btn-outline-primary:hover { 
            background: #ff6b35; 
            border-color: #ff6b35;
            transform: translateY(-1px);
        }
        
        .badge.bg-success { background: #28a745 !important; }
        .badge.bg-warning { background: #ffc107 !important; color: #000; }
        .badge.bg-info { background: #17a2b8 !important; }
        
        .alert-info { 
            background: linear-gradient(135deg, #ff6b35, #f7931e); 
            color: white; 
            border: none; 
            border-radius: 10px;
        }
        
        .modal-header { 
            background: linear-gradient(135deg, #ff6b35 0%, #f7931e 100%); 
            color: white; 
            border: none;
        }
        .modal-content { border-radius: 15px; border: none; overflow: hidden; }
        .btn-close { filter: invert(1); }
        
        .page-header { 
            background: linear-gradient(135deg, rgba(255, 107, 53, 0.1) 0%, rgba(247, 147, 30, 0.1) 100%);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .fade-in { animation: fadeIn 0.6s ease-in; }
        @keyframes fadeIn { 
            from { opacity: 0; transform: translateY(30px); } 
            to { opacity: 1; transform: translateY(0); } 
        }
        
        .col-lg-2-4 { flex: 0 0 20%; max-width: 20%; }
        @media (max-width: 991px) { .col-lg-2-4 { flex: 0 0 50%; max-width: 50%; } }
        @media (max-width: 576px) { .col-lg-2-4 { flex: 0 0 100%; max-width: 100%; } }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <main class="content fade-in">
        <div class="page-header">
            <div class="d-flex align-items-center">
                <i class="fas fa-chart-line me-3" style="font-size: 2.5rem; color: #ff6b35;"></i>
                <div>
                    <h1 style="color: #ff6b35; margin: 0;">Investment History</h1>
                    <p class="text-muted mb-0">Track your investment journey and financial progress</p>
                </div>
            </div>
        </div>

                <!-- Summary Cards -->
                <div class="row mb-4 fade-in">
                    <?php 
                    $cards = [
                        ['title' => 'Received Amount', 'value' => $totals['received_amount'], 'icon' => 'coins'],
                        ['title' => 'Total Withdrawn', 'value' => $totals['withdrawn'], 'icon' => 'money-bill-transfer'],
                        ['title' => 'Total Sent', 'value' => $totals['sent'], 'icon' => 'paper-plane']
                    ];
                    foreach ($cards as $card): ?>
                        <div class="col-lg-2-4 col-md-6 mb-3">
                            <div class="summary-card">
                                <i class="fas fa-<?= $card['icon'] ?>"></i>
                                <h3>Rs<?= number_format($card['value'], 2) ?></h3>
                                <p><?= $card['title'] ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Navigation Tabs -->
                <ul class="nav nav-tabs mb-4" id="historyTabs">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#payments">
                            <i class="fas fa-receipt me-2"></i>Payment History
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#withdrawals">
                            <i class="fas fa-money-bill-transfer me-2"></i>Withdrawal History
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#sent-money">
                            <i class="fas fa-paper-plane me-2"></i>Send Money History
                        </button>
                    </li>
                </ul>

                <!-- Tab Content -->
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="payments">
                        <?= renderTable($data['payments'], 'payment') ?>
                    </div>
                    <div class="tab-pane fade" id="withdrawals">
                        <?= renderTable($data['withdrawals'], 'withdrawal') ?>
                    </div>
                    <div class="tab-pane fade" id="sent-money">
                        <?= renderTable($data['sent_money'], 'sent') ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalBody"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function viewDetails(data, type) {
            const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
            const title = document.getElementById('modalTitle');
            const body = document.getElementById('modalBody');
            
            const configs = {
                payment: {
                    title: 'Payment Details',
                    fields: [
                        ['Payment Type', data.payment_type],
                        ['Amount', 'Rs' + parseFloat(data.amount || 0).toLocaleString()],
                        ['Received Amount', 'Rs' + parseFloat(data.received_amount || 0).toLocaleString()],
                        ['Profit', 'Rs' + parseFloat(data.profit_amount || 0).toLocaleString()],
                        ['Package', data.package_name],
                        ['Status', data.payment_status],
                        ['Date', new Date(data.payment_date).toLocaleString()]
                    ]
                },
                withdrawal: {
                    title: 'Withdrawal Details',
                    fields: [
                        ['Amount', 'Rs' + parseFloat(data.amount || 0).toLocaleString()],
                        ['Sender', data.sender_name],
                        ['Type', data.assignment_type],
                        ['Status', data.board_status || 'Completed'],
                        ['Date', new Date(data.confirmed_at).toLocaleString()]
                    ]
                },
                sent: {
                    title: 'Send Money Details',
                    fields: [
                        ['Receiver', data.receiver_name],
                        ['Email', data.receiver_email],
                        ['Amount', 'Rs' + parseFloat(data.amount || 0).toLocaleString()],
                        ['Type', data.payment_type],
                        ['Status', data.payment_status],
                        ['Date', new Date(data.payment_date).toLocaleString()]
                    ]
                }
            };

            const config = configs[type];
            title.textContent = config.title;
            
            let html = '<div class="row">';
            config.fields.forEach(([label, value]) => {
                if (value) {
                    html += `<div class="col-md-6 mb-2"><strong>${label}:</strong> ${value}</div>`;
                }
            });
            html += '</div>';
            body.innerHTML = html;
            modal.show();
        }
    </script>
</body>
</html>