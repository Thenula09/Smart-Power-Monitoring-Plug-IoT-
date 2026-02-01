<?php

date_default_timezone_set("Asia/Colombo");
// --- CONFIGURATION ---
$db_server = "localhost";
$db_name = "smart_energy";
$db_user = "yesitha";
$db_pass = "qaz2wsx";

// --- DATABASE CONNECTION ---
function getDb() {
    global $db_server, $db_name, $db_user, $db_pass;
    $connectionInfo = ["Database" => $db_name, "UID" => $db_user, "PWD" => $db_pass, "CharacterSet" => "UTF-8"];
    $conn = sqlsrv_connect($db_server, $connectionInfo);
    if ($conn === false) {
        die(print_r(sqlsrv_errors(), true));
    }
    return $conn;
}

session_start();
$conn = getDb();
$page = $_GET['page'] ?? 'dashboard';

// --- ROUTING ---
if ($page == 'login') {
    handle_login($conn);
} elseif ($page == 'logout') {
    session_destroy();
    header("Location: index.php?page=login");
    exit;
} elseif ($page == 'api' && isset($_GET['action'])) {
    handle_api($conn, $_GET['action']);
} elseif ($page == 'ajax' && isset($_GET['action'])) {
    handle_ajax($conn, $_GET['action']);
} else {
    if (!isset($_SESSION['user'])) {
        header("Location: index.php?page=login");
        exit;
    }
    
    if ($page == 'dashboard') {
        render_dashboard($conn);
    } elseif ($page == 'admin_devices' && $_SESSION['user']['role'] == 'admin') {
        handle_admin_devices($conn);
    } elseif ($page == 'admin_users' && $_SESSION['user']['role'] == 'admin') {
        handle_admin_users($conn);
    } elseif ($page == 'user_devices') {
        handle_user_devices($conn);
    } else {
        render_dashboard($conn);
    }
}

// --- API HANDLERS (for ESP32) ---
function handle_api($conn, $action) {
    header('Content-Type: application/json');
    if ($action == 'log_data' && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $device_id = $_POST['device_id'] ?? null;
        $voltage = (float)($_POST['voltage'] ?? 0);
        $current = (float)($_POST['current'] ?? 0);
        $power = (float)($_POST['power'] ?? 0);
        $energy = (float)($_POST['energy'] ?? 0);

        if (!$device_id) {
            echo json_encode(['error' => 'Device ID is required in POST data.']);
            exit;
        }
        
        $check_sql = "SELECT id, max_power FROM devices WHERE device_id_str = ?";
        $check_stmt = sqlsrv_query($conn, $check_sql, [$device_id]);
        if ($check_stmt === false) {
            echo json_encode(['error' => 'Database query failed', 'details' => sqlsrv_errors()]);
            exit;
        }
        $device = sqlsrv_fetch_array($check_stmt, SQLSRV_FETCH_ASSOC);
        if (!$device) {
            echo json_encode(['error' => "Device with ID '{$device_id}' not found."]);
            exit;
        }

        $sql = "INSERT INTO energy_log (device_id_str, voltage, [current], power, energy) VALUES (?, ?, ?, ?, ?)";
        $params = [$device_id, $voltage, $current, $power, $energy];
        $stmt = sqlsrv_query($conn, $sql, $params);

        if ($stmt === false) {
            echo json_encode(['error' => 'Database insert failed', 'details' => sqlsrv_errors()]);
        } else {
            if ($device['max_power'] !== null && $power > $device['max_power']) {
                $alert_sql = "INSERT INTO alerts (device_id_str, power_reading, max_power_setting) VALUES (?, ?, ?)";
                sqlsrv_query($conn, $alert_sql, [$device_id, $power, $device['max_power']]);
            }
            echo json_encode(['success' => 'Data logged successfully']);
        }
    } else {
        echo json_encode(['error' => 'Invalid API action']);
    }
    exit;
}

// --- AJAX HANDLERS (for Web UI) ---
function handle_ajax($conn, $action) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['user'])) {
        echo json_encode(['error' => 'Authentication required']);
        exit;
    }

    if ($action == 'device_status' && isset($_GET['device_id'])) {
        $device_id = (int)$_GET['device_id'];
        $sql = "SELECT device_ip, device_id_str, max_power FROM devices WHERE id = ?";
        $stmt = sqlsrv_query($conn, $sql, [$device_id]);
        $device = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

        if ($device && !empty($device['device_ip'])) {
            $url = "http://" . $device['device_ip'] . "/status";
            $context = stream_context_create(['http' => ['timeout' => 3]]);
            $json = @file_get_contents($url, false, $context);
            if ($json === false) {
                echo json_encode(['error' => 'Could not connect to device at ' . $device['device_ip']]);
            } else {
                $status = json_decode($json, true);
                if (!$status) {
                    echo json_encode(['error' => 'Invalid response from device']);
                    exit;
                }
                $periods = [
                    'daily' => ['unit' => 'DAY', 'amount' => 1],
                    'weekly' => ['unit' => 'DAY', 'amount' => 7],
                    'monthly' => ['unit' => 'DAY', 'amount' => 30]
                ];
                $consumption = [];
                foreach ($periods as $p => $conf) {
                    $period_start_sql = "DATEADD({$conf['unit']}, -{$conf['amount']}, GETDATE())";
                    $sql_start = "SELECT TOP 1 energy FROM energy_log WHERE device_id_str = ? AND log_time < {$period_start_sql} ORDER BY log_time DESC";
                    $stmt_start = sqlsrv_query($conn, $sql_start, [$device['device_id_str']]);
                    $start_energy = 0;
                    if ($stmt_start !== false && $row = sqlsrv_fetch_array($stmt_start, SQLSRV_FETCH_ASSOC)) {
                        $start_energy = $row['energy'];
                    }
                    $cons = $status['energy'] - $start_energy;
                    $consumption[$p] = $cons < 0 ? 0 : $cons;
                }
                $status['consumption'] = $consumption;
                $status['max_power'] = $device['max_power'];
                echo json_encode($status);
            }
        } else {
            echo json_encode(['error' => 'Device not found']);
        }
    } elseif ($action == 'device_control' && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $device_id = (int)$_POST['device_id'];
        $state = $_POST['state'] == 'on' ? 'on' : 'off';
        
        $sql = "SELECT device_ip FROM devices WHERE id = ?";
        $stmt = sqlsrv_query($conn, $sql, [$device_id]);
        $device = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

        if ($device && !empty($device['device_ip'])) {
            $url = "http://" . $device['device_ip'] . "/" . $state;
            $context = stream_context_create(['http' => ['timeout' => 5]]);
            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                echo json_encode(['error' => 'Failed to send command to device']);
            } else {
                echo json_encode(['message' => 'Command sent successfully!', 'response' => $response]);
            }
        } else {
            echo json_encode(['error' => 'Device not found']);
        }
    } elseif ($action == 'get_alerts') {
        $is_admin = $_SESSION['user']['role'] == 'admin';
        $sql = "SELECT a.*, d.name as device_name FROM alerts a JOIN devices d ON a.device_id_str = d.device_id_str WHERE a.is_read = 0";
        $params = [];
        if (!$is_admin) {
            $sql .= " AND d.owner_id = ?";
            $params[] = $_SESSION['user']['id'];
        }
        $sql .= " ORDER BY a.alert_time DESC";
        $stmt = sqlsrv_query($conn, $sql, $params);
        $alerts = [];
        if ($stmt !== false) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $row['alert_time'] = $row['alert_time'] instanceof DateTime ? $row['alert_time']->format('Y-m-d H:i:s') : $row['alert_time'];
                $alerts[] = $row;
            }
        }
        echo json_encode(['alerts' => $alerts]);
    } elseif ($action == 'mark_alerts_read' && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $is_admin = $_SESSION['user']['role'] == 'admin';
        $sql = "UPDATE alerts SET is_read = 1 WHERE is_read = 0";
        $params = [];
        if (!$is_admin) {
            $sql .= " AND device_id_str IN (SELECT device_id_str FROM devices WHERE owner_id = ?)";
            $params[] = $_SESSION['user']['id'];
        }
        sqlsrv_query($conn, $sql, $params);
        echo json_encode(['success' => true]);
    } elseif ($action == 'get_devices') {
        $is_admin = $_SESSION['user']['role'] == 'admin';
        $sql = "SELECT d.*, u.username as owner_name FROM devices d LEFT JOIN users u ON d.owner_id = u.id";
        $params = [];
        if (!$is_admin) {
            $sql .= " WHERE d.owner_id = ?";
            $params[] = $_SESSION['user']['id'];
        }
        $stmt = sqlsrv_query($conn, $sql, $params);
        $devices = [];
        if ($stmt !== false) {
            while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $devices[] = $r;
            }
        }
        echo json_encode(['devices' => $devices]);
    } elseif ($action == 'get_users') {
        $is_admin = $_SESSION['user']['role'] == 'admin';
        if (!$is_admin) {
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        $sql = "SELECT * FROM users";
        $stmt = sqlsrv_query($conn, $sql);
        $users = [];
        if ($stmt !== false) {
            while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $users[] = $r;
            }
        }
        echo json_encode(['users' => $users]);
    } elseif ($action == 'get_historical_data' && isset($_GET['device_id'])) {
        $device_id = (int)$_GET['device_id'];
        $dev_sql = "SELECT device_id_str FROM devices WHERE id = ?";
        $dev_stmt = sqlsrv_query($conn, $dev_sql, [$device_id]);
        $device = sqlsrv_fetch_array($dev_stmt, SQLSRV_FETCH_ASSOC);

        if (!$device) {
            echo json_encode(['error' => 'Device not found']);
            exit;
        }
        $device_id_str = $device['device_id_str'];

        $periods = [
            'hour'  => ['sql' => "DATEADD(HOUR, -1, GETDATE())"],
            'day'   => ['sql' => "DATEADD(DAY, -1, GETDATE())"],
            'week'  => ['sql' => "DATEADD(DAY, -7, GETDATE())"],
            'month' => ['sql' => "DATEADD(DAY, -30, GETDATE())"]
        ];
        
        $response_data = [];
        foreach ($periods as $period => $conf) {
            $data_sql = "SELECT log_time, power, voltage FROM energy_log WHERE device_id_str = ? AND log_time >= {$conf['sql']} ORDER BY log_time";
            $data_stmt = sqlsrv_query($conn, $data_sql, [$device_id_str]);
            
            $labels = [];
            $power_data = [];
            $voltage_data = [];

            if ($data_stmt !== false) {
                while ($row = sqlsrv_fetch_array($data_stmt, SQLSRV_FETCH_ASSOC)) {
                    $labels[] = $row['log_time'] instanceof DateTime ? $row['log_time']->format('c') : $row['log_time'];
                    $power_data[] = $row['power'];
                    $voltage_data[] = $row['voltage'];
                }
            }
            $response_data[$period] = [
                'labels' => $labels,
                'power' => $power_data,
                'voltage' => $voltage_data
            ];
        }
        echo json_encode($response_data);
    } else {
        echo json_encode(['error' => 'Invalid AJAX action']);
    }
    exit;
}

// --- PAGE HANDLERS ---
function handle_login($conn) {
    $error = "";
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $sql = "SELECT * FROM users WHERE username=? AND password=?";
        $params = [$_POST['username'], $_POST['password']];
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            $error = "Database error: " . print_r(sqlsrv_errors(), true);
        } elseif ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $_SESSION['user'] = $row;
            header("Location: index.php?page=dashboard");
            exit;
        } else {
            $error = "Invalid username or password";
        }
    }
    render_login_page($error);
}

function handle_admin_devices($conn) {
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add'])) {
        $owner_id = !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : null;
        $max_power = !empty($_POST['max_power']) ? (float)$_POST['max_power'] : null;
        $sql = "INSERT INTO devices (name, device_ip, device_id_str, owner_id, max_power) VALUES (?, ?, ?, ?, ?)";
        $stmt = sqlsrv_query($conn, $sql, [$_POST['name'], $_POST['ip'], $_POST['deviceid'], $owner_id, $max_power]);
        if ($stmt === false) {
            echo "<p class='error'>Error adding device: " . print_r(sqlsrv_errors(), true) . "</p>";
        }
    } elseif ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update'])) {
        $owner_id = !empty($_POST['owner_id']) ? (int)$_POST['owner_id'] : null;
        $max_power = !empty($_POST['max_power']) ? (float)$_POST['max_power'] : null;
        $sql = "UPDATE devices SET name=?, device_ip=?, device_id_str=?, owner_id=?, max_power=? WHERE id=?";
        $stmt = sqlsrv_query($conn, $sql, [$_POST['name'], $_POST['ip'], $_POST['deviceid'], $owner_id, $max_power, (int)$_POST['id']]);
        if ($stmt === false) {
            echo "<p class='error'>Error updating device: " . print_r(sqlsrv_errors(), true) . "</p>";
        }
    } elseif (isset($_GET['delete'])) {
        $sql = "DELETE FROM devices WHERE id=?";
        $stmt = sqlsrv_query($conn, $sql, [(int)$_GET['delete']]);
        if ($stmt === false) {
            echo "<p class='error'>Error deleting device: " . print_r(sqlsrv_errors(), true) . "</p>";
        } else {
            header("Location: index.php?page=admin_devices");
            exit;
        }
    }
    
    $users = [];
    $u_stmt = sqlsrv_query($conn, "SELECT id, username FROM users");
    if ($u_stmt !== false) {
        while ($r = sqlsrv_fetch_array($u_stmt, SQLSRV_FETCH_ASSOC)) {
            $users[] = $r;
        }
    }

    $devices = [];
    $d_stmt = sqlsrv_query($conn, "SELECT d.*, u.username as owner_name FROM devices d LEFT JOIN users u ON d.owner_id = u.id");
    if ($d_stmt !== false) {
        while ($r = sqlsrv_fetch_array($d_stmt, SQLSRV_FETCH_ASSOC)) {
            $devices[] = $r;
        }
    }

    $edit_device = null;
    if (isset($_GET['edit'])) {
        $e_stmt = sqlsrv_query($conn, "SELECT * FROM devices WHERE id=?", [(int)$_GET['edit']]);
        if ($e_stmt !== false) {
            $edit_device = sqlsrv_fetch_array($e_stmt, SQLSRV_FETCH_ASSOC);
        }
    }

    render_admin_devices_page($users, $devices, $edit_device);
}

function handle_admin_users($conn) {
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add'])) {
        $sql = "INSERT INTO users (username, password, role) VALUES (?, ?, ?)";
        $stmt = sqlsrv_query($conn, $sql, [$_POST['username'], $_POST['password'], $_POST['role']]);
        if ($stmt === false) {
            echo "<p class='error'>Error adding user: " . print_r(sqlsrv_errors(), true) . "</p>";
        }
    } elseif (isset($_GET['delete'])) {
        $sql = "DELETE FROM users WHERE id=? AND id != ?";
        $stmt = sqlsrv_query($conn, $sql, [(int)$_GET['delete'], $_SESSION['user']['id']]);
        if ($stmt === false) {
            echo "<p class='error'>Error deleting user: " . print_r(sqlsrv_errors(), true) . "</p>";
        } else {
            header("Location: index.php?page=admin_users");
            exit;
        }
    }
    
    $users = [];
    $stmt = sqlsrv_query($conn, "SELECT * FROM users");
    if ($stmt !== false) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $users[] = $r;
        }
    }

    render_admin_users_page($users);
}

function handle_user_devices($conn) {
    $current_user_id = $_SESSION['user']['id'];
    $error_message = null;

    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_device'])) {
        $name = $_POST['name'];
        $ip = $_POST['ip'];
        $deviceid = $_POST['deviceid'];
        $max_power = !empty($_POST['max_power']) ? (float)$_POST['max_power'] : null;

        $sql = "INSERT INTO devices (name, device_ip, device_id_str, owner_id, max_power) VALUES (?, ?, ?, ?, ?)";
        $params = [$name, $ip, $deviceid, $current_user_id, $max_power];
        $stmt = sqlsrv_query($conn, $sql, $params);

        if ($stmt === false) {
            $error_message = "Error adding device: " . print_r(sqlsrv_errors(), true);
        } else {
            header("Location: index.php?page=user_devices");
            exit;
        }
    }

    $devices = [];
    $sql = "SELECT * FROM devices WHERE owner_id = ?";
    $stmt = sqlsrv_query($conn, $sql, [$current_user_id]);
    if ($stmt !== false) {
        while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $devices[] = $r;
        }
    } else {
        $error_message = "Error fetching your devices: " . print_r(sqlsrv_errors(), true);
    }

    render_user_devices_page($devices, $error_message);
}

// --- HTML RENDERING FUNCTIONS ---
function render_header($title) {
    $user = $_SESSION['user'] ?? null;
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>{$title} - Smart Energy Monitor</title>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/moment@2.29.1/moment.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/moment-timezone@0.5.43/builds/moment-timezone-with-data.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-moment@1.0.0"></script>
        <style>
            body { 
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; 
                line-height: 1.6; 
                color: #333; 
                max-width: 1200px; 
                margin: 0 auto; 
                padding: 0 15px; 
                background-color: #f4f7f6; 
                min-height: 100vh;
            }
            h1, h2, h3, h4 { color: #0056b3; }
            a { color: #007bff; text-decoration: none; }
            a:hover { text-decoration: underline; }
            .container { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
            nav { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #ddd; padding-bottom: 10px; margin-bottom: 20px; }
            nav a { margin: 0 10px; }
            .user-info { font-weight: bold; }
            .device-card { border: 1px solid #ccc; margin: 15px 0; padding: 15px; border-radius: 8px; background: #fafafa; }
            .device-card h3 { margin-top: 0; }
            .status { font-family: monospace; font-size: 1.1em; background: #e9ecef; padding: 10px; border-radius: 4px; margin: 10px 0; }
            .alerts { background: #fff3cd; padding: 10px; border-radius: 4px; margin: 10px 0; }
            .alerts li { color: #856404; }
            button { background-color: #007bff; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; font-size: 1em; transition: background-color 0.3s; }
            button:hover { background-color: #0056b3; }
            .btn-off { background-color: #dc3545; }
            .btn-off:hover { background-color: #c82333; }
            .form-group { margin-bottom: 15px; }
            input[type="text"], input[type="password"], select, input[type="number"] { width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ccc; box-sizing: border-box; font-size: 1em; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { text-align: left; padding: 8px; border-bottom: 1px solid #ddd; }
            th { background-color: #007bff; color: white; position: sticky; top: 0; z-index: 1; }
            .error { color: #dc3545; border: 1px solid #dc3545; padding: 10px; border-radius: 4px; margin-bottom: 15px; background: #f8d7da; }
            .aggregates { margin: 10px 0; }
            #totals { margin-bottom: 20px; }
            #clock { text-align: center; font-size: 1.2em; margin: 10px 0; color: #0056b3; background: #e9ecef; padding: 10px; border-radius: 4px; }
            .chart-container { margin: 20px 0; }
            .chart-box { text-align: center; display: none; }
            .chart-box.active { display: block; }
            canvas { width: 100% !important; height: 300px !important; }
            select.chart-selector { margin: 10px 0; padding: 8px; border-radius: 4px; border: 1px solid #ccc; font-size: 1em; }
            .timezone-info { background: #d1ecf1; padding: 10px; border-radius: 4px; margin: 10px 0; text-align: center; color: #0c5460; }
            /* Login Page Styles */
            .login-container {
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            }
            .login-card {
                background: white;
                padding: 40px;
                border-radius: 12px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
                width: 100%;
                max-width: 400px;
                text-align: center;
                animation: fadeIn 0.5s ease-in-out;
            }
            .login-card h2 {
                margin-bottom: 20px;
                font-size: 1.8em;
                color: #0056b3;
            }
            .login-card input {
                border: 1px solid #ccc;
                border-radius: 8px;
                padding: 12px;
                margin-bottom: 15px;
                transition: border-color 0.3s;
            }
            .login-card input:focus {
                outline: none;
                border-color: #007bff;
                box-shadow: 0 0 5px rgba(0, 123, 255, 0.3);
            }
            .login-card button {
                width: 100%;
                padding: 12px;
                font-size: 1.1em;
                border-radius: 8px;
                transition: background-color 0.3s, transform 0.2s;
            }
            .login-card button:hover {
                transform: translateY(-2px);
            }
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }
        </style>
    </head>
    <body>
    <div class="container">
        <nav>
            <h1>Smart Energy Monitor</h1>
            <div>
HTML;
    if ($user) {
        echo "<span class='user-info'>Welcome, {$user['username']}</span>";
        echo "<a href='index.php?page=dashboard'>Dashboard</a>";
        echo "<a href='index.php?page=user_devices'>My Devices</a>";
        if ($user['role'] == 'admin') {
            echo "<a href='index.php?page=admin_devices'>Manage All Devices</a>";
            echo "<a href='index.php?page=admin_users'>Manage Users</a>";
        }
        echo "<a href='index.php?page=logout'>Logout</a>";
    }
    echo "</div></nav>";
    echo "<div class='timezone-info'>All times displayed in Colombo Time Zone (UTC+5:30)</div>";
    echo "<div id='clock'></div>";
}

function render_footer() {
    echo <<<HTML
    <script>
    function updateClock() {
        const now = new Date().toLocaleString('en-US', {timeZone: 'Asia/Colombo'});
        document.getElementById('clock').innerHTML = 'Current Time: ' + now;
    }
    setInterval(updateClock, 1000);
    updateClock();
    </script>
    </div></body></html>
HTML;
}

function render_login_page($error) {
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login - Smart Energy Monitor</title>
        <style>
            body {
                margin: 0;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
                min-height: 100vh;
                display: flex;
                justify-content: center;
                align-items: center;
            }
            .login-container {
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
            }
            .login-card {
                background: white;
                padding: 40px;
                border-radius: 12px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
                width: 100%;
                max-width: 400px;
                text-align: center;
                animation: fadeIn 0.5s ease-in-out;
            }
            .login-card h2 {
                margin-bottom: 20px;
                font-size: 1.8em;
                color: #0056b3;
            }
            .form-group {
                margin-bottom: 15px;
            }
            .login-card input {
                width: 100%;
                padding: 12px;
                border: 1px solid #ccc;
                border-radius: 8px;
                font-size: 1em;
                box-sizing: border-box;
                transition: border-color 0.3s, box-shadow 0.3s;
            }
            .login-card input:focus {
                outline: none;
                border-color: #007bff;
                box-shadow: 0 0 5px rgba(0, 123, 255, 0.3);
            }
            .login-card button {
                width: 100%;
                padding: 12px;
                font-size: 1.1em;
                background-color: #007bff;
                color: white;
                border: none;
                border-radius: 8px;
                cursor: pointer;
                transition: background-color 0.3s, transform 0.2s;
            }
            .login-card button:hover {
                background-color: #0056b3;
                transform: translateY(-2px);
            }
            .error {
                color: #dc3545;
                background: #f8d7da;
                padding: 10px;
                border-radius: 8px;
                margin-bottom: 15px;
                font-size: 0.9em;
            }
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }
        </style>
    </head>
    <body>
    <div class="login-container">
        <div class="login-card">
            <h2>Smart Energy Monitor</h2>
            <form method="POST" action="index.php?page=login">
                <div class="form-group">
                    <input name="username" placeholder="Username" required>
                </div>
                <div class="form-group">
                    <input type="password" name="password" placeholder="Password" required>
                </div>
HTML;
    if ($error) {
        echo "<div class='error'>{$error}</div>";
    }
    echo <<<HTML
                <button type="submit">Login</button>
            </form>
        </div>
    </div>
    </body>
    </html>
HTML;
}

function render_dashboard($conn) {
    render_header("Dashboard");
    $is_admin = $_SESSION['user']['role'] == 'admin';
    $sql = "SELECT d.*, u.username as owner_name FROM devices d LEFT JOIN users u ON d.owner_id = u.id";
    $params = [];
    if (!$is_admin) {
        $sql .= " WHERE d.owner_id = ?";
        $params = [$_SESSION['user']['id']];
    }
    $stmt = sqlsrv_query($conn, $sql, $params);
    
    if ($stmt === false) {
        echo "<p class='error'>Database error: " . print_r(sqlsrv_errors(), true) . "</p>";
        render_footer();
        return;
    }

    $devices = [];
    while ($r = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $devices[] = $r;
    }
    
    echo <<<HTML
    <div id="totals">
        <h2>Total Usage</h2>
        Current Power: <span id="total_power">0</span> W<br>
        Daily Consumption: <span id="total_daily">0</span> Wh<br>
        Weekly Consumption: <span id="total_weekly">0</span> Wh<br>
        Monthly Consumption: <span id="total_monthly">0</span> Wh
    </div>
    <div id="alerts">
        <h2>Alerts</h2>
        <ul id="alert_list"></ul>
        <button onclick="markRead()">Mark All Read</button>
    </div>
HTML;

    if (empty($devices)) {
        echo "<p>You do not have any devices. You can add one from the <a href='?page=user_devices'>My Devices</a> page.</p>";
    }

    foreach ($devices as $dev) {
        $owner_info = $is_admin ? " - Owner: " . ($dev['owner_name'] ?? 'Unassigned') : '';
        $max_power_info = $dev['max_power'] !== null ? " (Max: {$dev['max_power']} W)" : '';

        echo <<<HTML
        <div class="device-card">
            <h3>{$dev['name']} ({$dev['device_ip']}){$owner_info}{$max_power_info}</h3>
            <div id="status{$dev['id']}" class="status">Loading status...</div>
            <div class="aggregates">
                Daily: <span id="daily{$dev['id']}">0</span> Wh |
                Weekly: <span id="weekly{$dev['id']}">0</span> Wh |
                Monthly: <span id="monthly{$dev['id']}">0</span> Wh
            </div>
            <button onclick="controlDevice({$dev['id']},'on')">Turn ON</button>
            <button class="btn-off" onclick="controlDevice({$dev['id']},'off')">Turn OFF</button>
            <div class="form-group">
                <select class="chart-selector" id="chartSelector{$dev['id']}" onchange="showChart({$dev['id']}, this.value)">
                    <option value="">Select a Chart</option>
                    <option value="powerHourChart{$dev['id']}" selected>Power - Current Hour</option>
                    <option value="voltageHourChart{$dev['id']}">Voltage - Current Hour</option>
                    <option value="powerDayChart{$dev['id']}">Power - Last 24 Hours</option>
                    <option value="voltageDayChart{$dev['id']}">Voltage - Last 24 Hours</option>
                    <option value="powerWeekChart{$dev['id']}">Power - Last Week</option>
                    <option value="voltageWeekChart{$dev['id']}">Voltage - Last Week</option>
                    <option value="powerMonthChart{$dev['id']}">Power - Last Month</option>
                    <option value="voltageMonthChart{$dev['id']}">Voltage - Last Month</option>
                </select>
            </div>
            <div class="chart-container" id="chartContainer{$dev['id']}">
                <div class="chart-box active" id="powerHourChart{$dev['id']}_box">
                    <h4>Power (W) - Current Hour</h4>
                    <canvas id="powerHourChart{$dev['id']}"></canvas>
                </div>
                <div class="chart-box" id="voltageHourChart{$dev['id']}_box">
                    <h4>Voltage (V) - Current Hour</h4>
                    <canvas id="voltageHourChart{$dev['id']}"></canvas>
                </div>
                <div class="chart-box" id="powerDayChart{$dev['id']}_box">
                    <h4>Power (W) - Last 24 Hours</h4>
                    <canvas id="powerDayChart{$dev['id']}"></canvas>
                </div>
                <div class="chart-box" id="voltageDayChart{$dev['id']}_box">
                    <h4>Voltage (V) - Last 24 Hours</h4>
                    <canvas id="voltageDayChart{$dev['id']}"></canvas>
                </div>
                <div class="chart-box" id="powerWeekChart{$dev['id']}_box">
                    <h4>Power (W) - Last Week</h4>
                    <canvas id="powerWeekChart{$dev['id']}"></canvas>
                </div>
                <div class="chart-box" id="voltageWeekChart{$dev['id']}_box">
                    <h4>Voltage (V) - Last Week</h4>
                    <canvas id="voltageWeekChart{$dev['id']}"></canvas>
                </div>
                <div class="chart-box" id="powerMonthChart{$dev['id']}_box">
                    <h4>Power (W) - Last Month</h4>
                    <canvas id="powerMonthChart{$dev['id']}"></canvas>
                </div>
                <div class="chart-box" id="voltageMonthChart{$dev['id']}_box">
                    <h4>Voltage (V) - Last Month</h4>
                    <canvas id="voltageMonthChart{$dev['id']}"></canvas>
                </div>
            </div>
        </div>
        <script>
        function fetchStatus{$dev['id']}() {
            const statusEl = document.getElementById("status{$dev['id']}");
            fetch("index.php?page=ajax&action=device_status&device_id={$dev['id']}")
                .then(r => r.json())
                .then(d => {
                    if (d.error) {
                        statusEl.innerText = d.error;
                        statusEl.style.color = '#dc3545';
                        if (devicesData.hasOwnProperty({$dev['id']})) {
                            delete devicesData[{$dev['id']}];
                            updateTotals();
                        }
                        return;
                    }
                    statusEl.style.color = d.power > d.max_power && d.max_power !== null ? '#dc3545' : '#333';
                    statusEl.innerHTML = `
                        Voltage: <strong>\${d.voltage.toFixed(2)} V</strong> |
                        Current: <strong>\${d.current.toFixed(3)} A</strong> |
                        Power: <strong>\${d.power.toFixed(2)} W</strong> |
                        Energy: <strong>\${d.energy.toFixed(3)} Wh</strong> |
                        Switch: <strong>\${d.relay == 1 ? "OFF" : "ON"}</strong>`;
                    document.getElementById('daily{$dev['id']}').innerText = d.consumption.daily.toFixed(3);
                    document.getElementById('weekly{$dev['id']}').innerText = d.consumption.weekly.toFixed(3);
                    document.getElementById('monthly{$dev['id']}').innerText = d.consumption.monthly.toFixed(3);
                    
                    devicesData[{$dev['id']}] = d;
                    updateTotals();
                })
                .catch(err => {
                    statusEl.innerText = "Error fetching status. Check console.";
                    statusEl.style.color = '#dc3545';
                    if (devicesData.hasOwnProperty({$dev['id']})) {
                        delete devicesData[{$dev['id']}];
                        updateTotals();
                    }
                    console.error(err);
                });
        }

        function fetchHistoricalData{$dev['id']}() {
            fetch(`index.php?page=ajax&action=get_historical_data&device_id={$dev['id']}`)
                .then(r => r.json())
                .then(d => {
                    if (d.error) {
                        console.error("Error fetching historical data: ", d.error);
                        return;
                    }

                    powerHourChart{$dev['id']}.data.labels = d.hour.labels;
                    powerHourChart{$dev['id']}.data.datasets[0].data = d.hour.power;
                    powerHourChart{$dev['id']}.update();

                    voltageHourChart{$dev['id']}.data.labels = d.hour.labels;
                    voltageHourChart{$dev['id']}.data.datasets[0].data = d.hour.voltage;
                    voltageHourChart{$dev['id']}.update();
                    
                    powerDayChart{$dev['id']}.data.labels = d.day.labels;
                    powerDayChart{$dev['id']}.data.datasets[0].data = d.day.power;
                    powerDayChart{$dev['id']}.update();

                    voltageDayChart{$dev['id']}.data.labels = d.day.labels;
                    voltageDayChart{$dev['id']}.data.datasets[0].data = d.day.voltage;
                    voltageDayChart{$dev['id']}.update();
                    
                    powerWeekChart{$dev['id']}.data.labels = d.week.labels;
                    powerWeekChart{$dev['id']}.data.datasets[0].data = d.week.power;
                    powerWeekChart{$dev['id']}.update();

                    voltageWeekChart{$dev['id']}.data.labels = d.week.labels;
                    voltageWeekChart{$dev['id']}.data.datasets[0].data = d.week.voltage;
                    voltageWeekChart{$dev['id']}.update();
                    
                    powerMonthChart{$dev['id']}.data.labels = d.month.labels;
                    powerMonthChart{$dev['id']}.data.datasets[0].data = d.month.power;
                    powerMonthChart{$dev['id']}.update();

                    voltageMonthChart{$dev['id']}.data.labels = d.month.labels;
                    voltageMonthChart{$dev['id']}.data.datasets[0].data = d.month.voltage;
                    voltageMonthChart{$dev['id']}.update();
                })
                .catch(err => console.error("Error fetching historical data:", err));
        }

        setInterval(fetchStatus{$dev['id']}, 5000);
        setInterval(fetchHistoricalData{$dev['id']}, 60000);
        fetchStatus{$dev['id']}();
        fetchHistoricalData{$dev['id']}();

        const powerHourCtx{$dev['id']} = document.getElementById('powerHourChart{$dev['id']}').getContext('2d');
        const powerHourChart{$dev['id']} = new Chart(powerHourCtx{$dev['id']}, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Power (W)',
                    data: [],
                    borderColor: 'blue',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    fill: false,
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    x: { 
                        type: 'time', 
                        time: { 
                            unit: 'minute', 
                            timezone: 'Asia/Colombo',
                            displayFormats: {
                                minute: 'HH:mm'
                            }
                        },
                        title: {
                            display: true,
                            text: 'Time'
                        }
                    },
                    y: { 
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Power (W)'
                        }
                    }
                }
            }
        });

        const voltageHourCtx{$dev['id']} = document.getElementById('voltageHourChart{$dev['id']}').getContext('2d');
        const voltageHourChart{$dev['id']} = new Chart(voltageHourCtx{$dev['id']}, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Voltage (V)',
                    data: [],
                    borderColor: 'orange',
                    backgroundColor: 'rgba(255, 165, 0, 0.1)',
                    fill: false,
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    x: { 
                        type: 'time', 
                        time: { 
                            unit: 'minute', 
                            timezone: 'Asia/Colombo',
                            displayFormats: {
                                minute: 'HH:mm'
                            }
                        },
                        title: {
                            display: true,
                            text: 'Time'
                        }
                    },
                    y: { 
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Voltage (V)'
                        }
                    }
                }
            }
        });

        const powerDayCtx{$dev['id']} = document.getElementById('powerDayChart{$dev['id']}').getContext('2d');
        const powerDayChart{$dev['id']} = new Chart(powerDayCtx{$dev['id']}, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Power (W)',
                    data: [],
                    borderColor: 'blue',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    fill: false,
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    x: { 
                        type: 'time', 
                        time: { 
                            unit: 'hour', 
                            timezone: 'Asia/Colombo',
                            displayFormats: {
                                hour: 'HH:00'
                            }
                        },
                        title: {
                            display: true,
                            text: 'Time'
                        }
                    },
                    y: { 
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Power (W)'
                        }
                    }
                }
            }
        });

        const voltageDayCtx{$dev['id']} = document.getElementById('voltageDayChart{$dev['id']}').getContext('2d');
        const voltageDayChart{$dev['id']} = new Chart(voltageDayCtx{$dev['id']}, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Voltage (V)',
                    data: [],
                    borderColor: 'orange',
                    backgroundColor: 'rgba(255, 165, 0, 0.1)',
                    fill: false,
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    x: { 
                        type: 'time', 
                        time: { 
                            unit: 'hour', 
                            timezone: 'Asia/Colombo',
                            displayFormats: {
                                hour: 'HH:00'
                            }
                        },
                        title: {
                            display: true,
                            text: 'Time'
                        }
                    },
                    y: { 
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Voltage (V)'
                        }
                    }
                }
            }
        });

        const powerWeekCtx{$dev['id']} = document.getElementById('powerWeekChart{$dev['id']}').getContext('2d');
        const powerWeekChart{$dev['id']} = new Chart(powerWeekCtx{$dev['id']}, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Power (W)',
                    data: [],
                    borderColor: 'blue',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    fill: false,
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    x: { 
                        type: 'time', 
                        time: { 
                            unit: 'day', 
                            timezone: 'Asia/Colombo',
                            displayFormats: {
                                day: 'MMM DD'
                            }
                        },
                        title: {
                            display: true,
                            text: 'Date'
                        }
                    },
                    y: { 
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Power (W)'
                        }
                    }
                }
            }
        });

        const voltageWeekCtx{$dev['id']} = document.getElementById('voltageWeekChart{$dev['id']}').getContext('2d');
        const voltageWeekChart{$dev['id']} = new Chart(voltageWeekCtx{$dev['id']}, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Voltage (V)',
                    data: [],
                    borderColor: 'orange',
                    backgroundColor: 'rgba(255, 165, 0, 0.1)',
                    fill: false,
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    x: { 
                        type: 'time', 
                        time: { 
                            unit: 'day', 
                            timezone: 'Asia/Colombo',
                            displayFormats: {
                                day: 'MMM DD'
                            }
                        },
                        title: {
                            display: true,
                            text: 'Date'
                        }
                    },
                    y: { 
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Voltage (V)'
                        }
                    }
                }
            }
        });

        const powerMonthCtx{$dev['id']} = document.getElementById('powerMonthChart{$dev['id']}').getContext('2d');
        const powerMonthChart{$dev['id']} = new Chart(powerMonthCtx{$dev['id']}, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Power (W)',
                    data: [],
                    borderColor: 'blue',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    fill: false,
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    x: { 
                        type: 'time', 
                        time: { 
                            unit: 'day', 
                            timezone: 'Asia/Colombo',
                            displayFormats: {
                                day: 'MMM DD'
                            }
                        },
                        title: {
                            display: true,
                            text: 'Date'
                        }
                    },
                    y: { 
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Power (W)'
                        }
                    }
                }
            }
        });

        const voltageMonthCtx{$dev['id']} = document.getElementById('voltageMonthChart{$dev['id']}').getContext('2d');
        const voltageMonthChart{$dev['id']} = new Chart(voltageMonthCtx{$dev['id']}, {
            type: 'line',
            data: {
                labels: [],
                datasets: [{
                    label: 'Voltage (V)',
                    data: [],
                    borderColor: 'orange',
                    backgroundColor: 'rgba(255, 165, 0, 0.1)',
                    fill: false,
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    x: { 
                        type: 'time', 
                        time: { 
                            unit: 'day', 
                            timezone: 'Asia/Colombo',
                            displayFormats: {
                                day: 'MMM DD'
                            }
                        },
                        title: {
                            display: true,
                            text: 'Date'
                        }
                    },
                    y: { 
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Voltage (V)'
                        }
                    }
                }
            }
        });

        showChart({$dev['id']}, 'powerHourChart{$dev['id']}');
        </script>
HTML;
    }
    echo <<<HTML
    <script>
    let devicesData = {};
    let shownAlerts = new Set();
    
    function updateTotals() {
        let totalPower = 0;
        let totalDaily = 0;
        let totalWeekly = 0;
        let totalMonthly = 0;
        
        for (const id in devicesData) {
            if (devicesData.hasOwnProperty(id) && devicesData[id] && !devicesData[id].error) {
                const data = devicesData[id];
                totalPower += data.power;
                totalDaily += data.consumption.daily;
                totalWeekly += data.consumption.weekly;
                totalMonthly += data.consumption.monthly;
            }
        }

        document.getElementById('total_power').innerText = totalPower.toFixed(2);
        document.getElementById('total_daily').innerText = totalDaily.toFixed(3);
        document.getElementById('total_weekly').innerText = totalWeekly.toFixed(3);
        document.getElementById('total_monthly').innerText = totalMonthly.toFixed(3);
    }
    
    function controlDevice(id, state) {
        fetch("index.php?page=ajax&action=device_control", {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: "device_id="+id+"&state="+state
        })
            .then(r => r.json())
            .then(d => {
                alert(d.message || d.error);
                const fetchFunc = window['fetchStatus' + id];
                if (typeof fetchFunc === 'function') {
                    fetchFunc();
                }
            });
    }
    
    function fetchAlerts() {
        fetch("index.php?page=ajax&action=get_alerts")
            .then(r => r.json())
            .then(d => {
                let ul = document.getElementById('alert_list');
                ul.innerHTML = '';
                d.alerts.forEach(a => {
                    let li = document.createElement('li');
                    li.innerText = `\${a.alert_time}: Device \${a.device_name} exceeded max power (\${a.power_reading.toFixed(2)} W > \${a.max_power_setting.toFixed(2)} W)`;
                    ul.appendChild(li);

                    if (!shownAlerts.has(a.id)) {
                        const alertMessage = `ALERT!\\n\\n` +
                                           `Device: \${a.device_name}\\n` +
                                           `Exceeded max power setting.\\n\\n` +
                                           `Reading: \${a.power_reading.toFixed(2)} W (Max: \${a.max_power_setting.toFixed(2)} W)`;
                        alert(alertMessage);
                        shownAlerts.add(a.id);
                    }
                });
            });
    }
    
    function showChart(deviceId, chartId) {
        const container = document.getElementById('chartContainer' + deviceId);
        if (!container) return;
        const chartBoxes = container.getElementsByClassName('chart-box');
        for (let box of chartBoxes) {
            box.classList.remove('active');
        }
        if (chartId) {
            const selectedBox = document.getElementById(chartId + '_box');
            if (selectedBox) {
                selectedBox.classList.add('active');
            }
        }
    }
    
    function markRead() {
        fetch("index.php?page=ajax&action=mark_alerts_read", {method: 'POST'})
            .then(() => fetchAlerts());
    }
    
    setInterval(fetchAlerts, 5000);
    fetchAlerts();
    updateTotals();
    </script>
HTML;
    render_footer();
}

function render_admin_devices_page($users, $devices, $edit_device) {
    render_header("Admin: Manage All Devices");
    $title = $edit_device ? 'Edit Device' : 'Add New Device';
    $button_name = $edit_device ? 'update' : 'add';
    $button_text = $edit_device ? 'Update Device' : 'Add Device';
    $name_val = $edit_device ? htmlspecialchars($edit_device['name']) : '';
    $ip_val = $edit_device ? htmlspecialchars($edit_device['device_ip']) : '';
    $deviceid_val = $edit_device ? htmlspecialchars($edit_device['device_id_str']) : '';
    $max_power_val = $edit_device ? htmlspecialchars($edit_device['max_power']) : '';
    $owner_id = $edit_device ? $edit_device['owner_id'] : '';
    $hidden = $edit_device ? "<input type='hidden' name='id' value='{$edit_device['id']}'>" : '';

    echo "<h2>{$title}</h2>";
    echo '<form method="POST" action="index.php?page=admin_devices">';
    echo $hidden;
    echo '<div class="form-group"><input name="name" placeholder="Device Name" value="' . $name_val . '" required></div>';
    echo '<div class="form-group"><input name="ip" placeholder="Device IP Address" value="' . $ip_val . '" required></div>';
    echo '<div class="form-group"><input name="deviceid" placeholder="Unique Device ID (e.g., PZEM001)" value="' . $deviceid_val . '" required></div>';
    echo '<div class="form-group"><input type="number" step="0.01" name="max_power" placeholder="Max Power (W)" value="' . $max_power_val . '"></div>';
    echo '<div class="form-group"><select name="owner_id"><option value="">-- Unassigned --</option>';
    foreach ($users as $u) {
        $selected = ($owner_id == $u['id']) ? 'selected' : '';
        echo "<option value='{$u['id']}' {$selected}>" . htmlspecialchars($u['username']) . "</option>";
    }
    echo '</select></div>';
    echo "<button name='{$button_name}'>{$button_text}</button></form>";
    
    echo '<h2>Existing Devices</h2>';
    echo '<table id="devicesTable"><tr><th>Name</th><th>IP</th><th>Device ID</th><th>Max Power (W)</th><th>Owner</th><th>Actions</th></tr>';
    foreach ($devices as $dev) {
        $owner = $dev['owner_name'] ? htmlspecialchars($dev['owner_name']) : 'Unassigned';
        $max_power = $dev['max_power'] !== null ? htmlspecialchars($dev['max_power']) : 'Not set';
        echo "<tr><td>" . htmlspecialchars($dev['name']) . "</td><td>" . htmlspecialchars($dev['device_ip']) . "</td><td>" . htmlspecialchars($dev['device_id_str']) . "</td><td>{$max_power}</td><td>{$owner}</td>";
        echo "<td><a href='?page=admin_devices&edit={$dev['id']}'>Edit</a> | <a href='?page=admin_devices&delete={$dev['id']}' onclick='return confirm(\"Are you sure?\")'>Delete</a></td></tr>";
    }
    echo '</table>';
    echo <<<HTML
    <script>
    function updateDevicesTable() {
        fetch("index.php?page=ajax&action=get_devices")
            .then(r => r.json())
            .then(d => {
                if (d.error) {
                    console.error(d.error);
                    return;
                }
                const table = document.getElementById('devicesTable');
                if (!table) return;
                table.innerHTML = '<tr><th>Name</th><th>IP</th><th>Device ID</th><th>Max Power (W)</th><th>Owner</th><th>Actions</th></tr>';
                d.devices.forEach(dev => {
                    const owner = dev.owner_name ? dev.owner_name : 'Unassigned';
                    const max_power = dev.max_power !== null ? dev.max_power : 'Not set';
                    const row = `<tr>
                        <td>\${dev.name}</td>
                        <td>\${dev.device_ip}</td>
                        <td>\${dev.device_id_str}</td>
                        <td>\${max_power}</td>
                        <td>\${owner}</td>
                        <td><a href="?page=admin_devices&edit=\${dev.id}">Edit</a> | <a href="?page=admin_devices&delete=\${dev.id}" onclick="return confirm('Are you sure?')">Delete</a></td>
                    </tr>`;
                    table.innerHTML += row;
                });
            })
            .catch(err => console.error('Error updating devices table:', err));
    }
    setInterval(updateDevicesTable, 10000);
    updateDevicesTable();
    </script>
HTML;
    render_footer();
}

function render_admin_users_page($users) {
    render_header("Admin: Manage Users");
    echo '<h2>Add New User</h2>';
    echo '<form method="POST" action="index.php?page=admin_users">';
    echo '<div class="form-group"><input name="username" placeholder="Username" required></div>';
    echo '<div class="form-group"><input type="password" name="password" placeholder="Password" required></div>';
    echo '<div class="form-group"><select name="role"><option value="user">User</option><option value="admin">Admin</option></select></div>';
    echo '<button name="add">Add User</button></form>';
    
    echo '<h2>Existing Users</h2>';
    echo '<table id="usersTable"><tr><th>ID</th><th>Username</th><th>Role</th><th>Action</th></tr>';
    foreach ($users as $u) {
        $delete_link = $u['id'] != $_SESSION['user']['id'] ? "<a href='?page=admin_users&delete={$u['id']}' onclick='return confirm(\"Are you sure?\")'>Delete</a>" : '';
        echo "<tr><td>{$u['id']}</td><td>" . htmlspecialchars($u['username']) . "</td><td>" . htmlspecialchars($u['role']) . "</td><td>{$delete_link}</td></tr>";
    }
    echo '</table>';
    echo <<<HTML
    <script>
    function updateUsersTable() {
        fetch("index.php?page=ajax&action=get_users")
            .then(r => r.json())
            .then(d => {
                if (d.error) {
                    console.error(d.error);
                    return;
                }
                const table = document.getElementById('usersTable');
                if (!table) return;
                table.innerHTML = '<tr><th>ID</th><th>Username</th><th>Role</th><th>Action</th></tr>';
                d.users.forEach(u => {
                    const deleteLink = u.id != {$_SESSION['user']['id']} ? `<a href="?page=admin_users&delete=\${u.id}" onclick="return confirm('Are you sure?')">Delete</a>` : '';
                    const row = `<tr>
                        <td>\${u.id}</td>
                        <td>\${u.username}</td>
                        <td>\${u.role}</td>
                        <td>\${deleteLink}</td>
                    </tr>`;
                    table.innerHTML += row;
                });
            })
            .catch(err => console.error('Error updating users table:', err));
    }
    setInterval(updateUsersTable, 10000);
    updateUsersTable();
    </script>
HTML;
    render_footer();
}

function render_user_devices_page($devices, $error = null) {
    render_header("My Devices");
    
    if ($error) {
        echo "<p class='error'>{$error}</p>";
    }

    echo '<h2>Add New Device</h2>';
    echo '<form method="POST" action="index.php?page=user_devices">';
    echo '<div class="form-group"><input name="name" placeholder="Device Name (e.g., Living Room AC)" required></div>';
    echo '<div class="form-group"><input name="ip" placeholder="Device IP Address" required></div>';
    echo '<div class="form-group"><input name="deviceid" placeholder="Unique Device ID (e.g., PZEM001)" required></div>';
    echo '<div class="form-group"><input type="number" step="0.01" name="max_power" placeholder="Max Power Threshold in Watts (Optional)"></div>';
    echo '<button name="add_device">Add Device</button></form>';
    
    echo '<h2>Your Registered Devices</h2>';
    if (empty($devices)) {
        echo "<p>You have not added any devices yet.</p>";
    } else {
        echo '<table><tr><th>Name</th><th>IP Address</th><th>Device ID</th><th>Max Power (W)</th></tr>';
        foreach ($devices as $dev) {
            $max_power = $dev['max_power'] !== null ? htmlspecialchars($dev['max_power']) : 'Not set';
            echo "<tr>";
            echo "<td>" . htmlspecialchars($dev['name']) . "</td>";
            echo "<td>" . htmlspecialchars($dev['device_ip']) . "</td>";
            echo "<td>" . htmlspecialchars($dev['device_id_str']) . "</td>";
            echo "<td>" . $max_power . "</td>";
            echo "</tr>";
        }
        echo '</table>';
        echo "<p style='margin-top: 10px; font-style: italic;'>Note: To edit or delete a device, please contact an administrator.</p>";
    }

    render_footer();
}
?>