CREATE TABLE IF NOT EXISTS `test_x86stock_setting` (`key` varchar(100) NOT NULL, `value` text NOT NULL, PRIMARY KEY (`key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8;
CREATE TABLE IF NOT EXISTS `test_x86stock_product` (
            `product_id` int(11) NOT NULL,
            `barcode` varchar(80) DEFAULT NULL,
            `cost_price` decimal(15,4) NOT NULL DEFAULT '0.0000',
            `partner_price` decimal(15,4) NOT NULL DEFAULT '0.0000',
            `min_stock` int(11) NOT NULL DEFAULT '1',
            `lead_time_days` int(11) NOT NULL DEFAULT '20',
            `location` varchar(100) NOT NULL DEFAULT '',
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`product_id`), UNIQUE KEY `barcode` (`barcode`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
CREATE TABLE IF NOT EXISTS `test_x86stock_sale` (
            `sale_id` int(11) NOT NULL AUTO_INCREMENT,
            `customer_type` varchar(20) NOT NULL DEFAULT 'retail',
            `payment_code` varchar(60) NOT NULL,
            `payment_name` varchar(120) NOT NULL,
            `commission_rate` decimal(8,4) NOT NULL DEFAULT '0.0000',
            `tax_rate` decimal(8,4) NOT NULL DEFAULT '3.0000',
            `gross_total` decimal(15,2) NOT NULL DEFAULT '0.00',
            `commission_total` decimal(15,2) NOT NULL DEFAULT '0.00',
            `tax_total` decimal(15,2) NOT NULL DEFAULT '0.00',
            `cost_total` decimal(15,2) NOT NULL DEFAULT '0.00',
            `profit_total` decimal(15,2) NOT NULL DEFAULT '0.00',
            `note` varchar(255) NOT NULL DEFAULT '',
            `date_created` datetime NOT NULL,
            PRIMARY KEY (`sale_id`), KEY `date_created` (`date_created`), KEY `payment_code` (`payment_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
CREATE TABLE IF NOT EXISTS `test_x86stock_sale_item` (
            `sale_item_id` int(11) NOT NULL AUTO_INCREMENT,
            `sale_id` int(11) NOT NULL,
            `product_id` int(11) NOT NULL,
            `name` varchar(255) NOT NULL,
            `model` varchar(100) NOT NULL DEFAULT '',
            `quantity` int(11) NOT NULL,
            `unit_price` decimal(15,2) NOT NULL,
            `unit_cost` decimal(15,4) NOT NULL DEFAULT '0.0000',
            `line_total` decimal(15,2) NOT NULL,
            `commission_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
            `tax_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
            `profit_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
            PRIMARY KEY (`sale_item_id`), KEY `sale_id` (`sale_id`), KEY `product_id` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
CREATE TABLE IF NOT EXISTS `test_x86stock_purchase` (
            `purchase_id` int(11) NOT NULL AUTO_INCREMENT,
            `supplier` varchar(150) NOT NULL DEFAULT '',
            `note` varchar(255) NOT NULL DEFAULT '',
            `total` decimal(15,2) NOT NULL DEFAULT '0.00',
            `date_created` datetime NOT NULL,
            PRIMARY KEY (`purchase_id`), KEY `date_created` (`date_created`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
CREATE TABLE IF NOT EXISTS `test_x86stock_purchase_item` (
            `purchase_item_id` int(11) NOT NULL AUTO_INCREMENT,
            `purchase_id` int(11) NOT NULL,
            `product_id` int(11) NOT NULL,
            `quantity` int(11) NOT NULL,
            `unit_cost` decimal(15,4) NOT NULL,
            `old_cost` decimal(15,4) NOT NULL DEFAULT '0.0000',
            `new_avg_cost` decimal(15,4) NOT NULL DEFAULT '0.0000',
            PRIMARY KEY (`purchase_item_id`), KEY `purchase_id` (`purchase_id`), KEY `product_id` (`product_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
CREATE TABLE IF NOT EXISTS `test_x86stock_movement` (
            `movement_id` bigint(20) NOT NULL AUTO_INCREMENT,
            `product_id` int(11) NOT NULL,
            `type` varchar(30) NOT NULL,
            `reference_id` int(11) NOT NULL DEFAULT '0',
            `quantity_delta` int(11) NOT NULL,
            `qty_before` int(11) NOT NULL,
            `qty_after` int(11) NOT NULL,
            `unit_cost` decimal(15,4) NOT NULL DEFAULT '0.0000',
            `note` varchar(255) NOT NULL DEFAULT '',
            `date_created` datetime NOT NULL,
            PRIMARY KEY (`movement_id`), KEY `product_id` (`product_id`), KEY `date_created` (`date_created`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
CREATE TABLE IF NOT EXISTS `test_x86stock_auth` (
            `auth_id` int(11) NOT NULL AUTO_INCREMENT,
            `token_hash` char(64) NOT NULL,
            `user_agent` varchar(255) NOT NULL DEFAULT '',
            `expires_at` datetime NOT NULL,
            `date_created` datetime NOT NULL,
            PRIMARY KEY (`auth_id`), UNIQUE KEY `token_hash` (`token_hash`), KEY `expires_at` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
CREATE TABLE IF NOT EXISTS `test_x86stock_commission` (
            `commission_id` int(11) NOT NULL AUTO_INCREMENT,
            `code` varchar(60) NOT NULL,
            `name` varchar(150) NOT NULL,
            `rate` decimal(8,4) NOT NULL DEFAULT '0.0000',
            `valid_from` date DEFAULT NULL,
            `valid_to` date DEFAULT NULL,
            `active` tinyint(1) NOT NULL DEFAULT '1',
            `sort_order` int(11) NOT NULL DEFAULT '0',
            `notes` varchar(255) NOT NULL DEFAULT '',
            PRIMARY KEY (`commission_id`), KEY `code` (`code`), KEY `dates` (`valid_from`,`valid_to`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
