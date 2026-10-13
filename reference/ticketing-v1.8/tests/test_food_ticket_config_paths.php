<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

final class FoodTicketFakeStatement
{
    public function __construct(private array $row) {}
    public function fetch(): array { return $this->row; }
}

final class FoodTicketFakeDb
{
    public function __construct(private array $row) {}
    public function query(string $sql): FoodTicketFakeStatement { return new FoodTicketFakeStatement($this->row); }
}

$GLOBALS['food_ticket_test_db'] = new FoodTicketFakeDb([
    'id' => 1,
    'attendance_path' => 'C:\\Data\\attendance-selected.mdb',
    'printer_mode' => 'tcp_raw',
    'printer_host' => '192.0.2.10',
    'printer_port' => 9100,
]);
function db(): FoodTicketFakeDb { return $GLOBALS['food_ticket_test_db']; }
function cfg(string $key, mixed $default = null): mixed { return $default; }

require APP_ROOT . '/food-ticket.php';
$config = food_ticket_config(true);
if ($config['attendance_path'] !== 'C:\\Data\\attendance-selected.mdb') {
    fwrite(STDERR, "FAIL attendance path was replaced by environment fallback\n");
    exit(1);
}
if (array_key_exists('orders_path', $config) || array_key_exists('orders_table', $config) || array_key_exists('ordersPath', $config)) {
    fwrite(STDERR, "FAIL legacy Access-order configuration was exposed to the live PHP config\n");
    exit(1);
}
echo "PASS TENTER path survives; legacy Access-order fields are not exposed\n";
