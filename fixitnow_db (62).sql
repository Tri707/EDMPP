-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Apr 17, 2025 at 09:20 PM
-- Server version: 9.1.0
-- PHP Version: 8.3.14

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `fixitnow_db`
--

DELIMITER $$
--
-- Procedures
--
DROP PROCEDURE IF EXISTS `create_quote_notifications`$$
CREATE DEFINER=`root`@`localhost` PROCEDURE `create_quote_notifications` (IN `p_customer_id` INT, IN `p_device_type` VARCHAR(50), IN `p_issue_description` TEXT)   BEGIN
    -- Declare variables
    DECLARE v_request_id INT;
    DECLARE v_error_message TEXT;

    -- Declare handler for errors
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        GET DIAGNOSTICS CONDITION 1
            v_error_message = MESSAGE_TEXT;
        
        INSERT INTO notification_logs (action, error_message)
        VALUES ('create_quote_notifications_error', v_error_message);
            
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'حدث خطأ في إنشاء الطلب. الرجاء المحاولة مرة أخرى.';
    END;

    -- Start transaction
    START TRANSACTION;
    
    -- Validate customer exists
    IF NOT EXISTS (SELECT 1 FROM users WHERE id = p_customer_id AND role = 'customer') THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'معرف العميل غير صالح';
    END IF;
    
    -- Validate device type
    IF p_device_type NOT IN ('smartphone', 'laptop', 'tablet', 'desktop', 'gaming', 'tv') THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'نوع الجهاز غير صالح';
    END IF;
    
    -- Insert the quote request
    INSERT INTO quote_requests (
        customer_id, 
        device_type, 
        issue_description,
        status
    ) VALUES (
        p_customer_id, 
        p_device_type, 
        p_issue_description,
        'pending'
    );
    
    -- Get the request ID
    SET v_request_id = LAST_INSERT_ID();
    
    -- Insert notifications
    INSERT INTO notifications (
        provider_id,
        customer_id,
        device_type,
        issue_description,
        type,
        reference_id,
        message,
        status
    )
    SELECT 
        p.id,
        p_customer_id,
        p_device_type,
        p_issue_description,
        'quote_request',
        v_request_id,
        CONCAT('طلب تسعير جديد لإصلاح ', p_device_type),
        'pending'
    FROM providers p
    WHERE EXISTS (
        SELECT 1 FROM users u 
        WHERE u.id = p.user_id 
        AND u.role = 'provider'
    );
    
    -- Log success
    INSERT INTO notification_logs (action, error_message)
    VALUES ('create_quote_notifications_success', CONCAT('تم إنشاء الطلب رقم: ', v_request_id));

    -- Commit the transaction
    COMMIT;

    -- Return success
    SELECT 'success' as status, v_request_id as request_id;

END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

DROP TABLE IF EXISTS `bookings`;
CREATE TABLE IF NOT EXISTS `bookings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL,
  `provider_id` int NOT NULL,
  `service_id` int DEFAULT NULL,
  `booking_date` date NOT NULL,
  `booking_time` time NOT NULL,
  `status` enum('pending','confirmed','completed','cancelled') COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `total_price` decimal(10,2) NOT NULL,
  `payment_status` enum('unpaid','paid','refunded') COLLATE utf8mb4_general_ci DEFAULT 'unpaid',
  `notes` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`),
  KEY `provider_id` (`provider_id`),
  KEY `service_id` (`service_id`)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bookings`
--

INSERT INTO `bookings` (`id`, `customer_id`, `provider_id`, `service_id`, `booking_date`, `booking_time`, `status`, `total_price`, `payment_status`, `notes`, `created_at`, `updated_at`) VALUES
(58, 70, 35, 25, '2025-04-21', '16:00:00', 'completed', 630.00, 'unpaid', 'لالالا', '2025-04-17 04:55:38', '2025-04-17 05:09:12'),
(59, 70, 35, 26, '2025-04-21', '11:00:00', 'cancelled', 56.00, 'unpaid', 'tttt', '2025-04-17 05:15:02', '2025-04-17 11:26:11'),
(60, 70, 35, 25, '2025-04-20', '13:00:00', 'pending', 630.00, 'unpaid', 'تساتت', '2025-04-17 11:59:42', '2025-04-17 11:59:42');

--
-- Triggers `bookings`
--
DROP TRIGGER IF EXISTS `booking_notification`;
DELIMITER $$
CREATE TRIGGER `booking_notification` AFTER INSERT ON `bookings` FOR EACH ROW BEGIN
    -- First check if the provider exists in the providers table
    DECLARE provider_exists INT;
    
    -- Handle SQL exceptions
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
    
    SELECT COUNT(*) INTO provider_exists FROM providers WHERE id = NEW.provider_id;
    
    -- Only insert notification if the provider exists
    IF provider_exists > 0 THEN
        INSERT INTO notifications (provider_id, type, reference_id, message, status) 
        VALUES (
            NEW.provider_id,
            'booking',
            NEW.id,
            'لديك حجز جديد',
            'pending'
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `booking_status_history`
--

DROP TABLE IF EXISTS `booking_status_history`;
CREATE TABLE IF NOT EXISTS `booking_status_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `booking_id` int NOT NULL,
  `status` varchar(50) NOT NULL,
  `created_by` varchar(20) NOT NULL,
  `user_id` int DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `booking_id` (`booking_id`),
  KEY `user_id` (`user_id`)
) ENGINE=MyISAM AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `booking_status_history`
--

INSERT INTO `booking_status_history` (`id`, `booking_id`, `status`, `created_by`, `user_id`, `notes`, `created_at`) VALUES
(38, 58, 'pending', 'customer', 70, 'Booking created', '2025-04-17 04:55:38'),
(39, 58, 'pending', 'provider', 73, '', '2025-04-17 05:05:35'),
(40, 58, 'confirmed', 'provider', 73, '', '2025-04-17 05:05:42'),
(41, 58, 'pending', 'provider', 73, '', '2025-04-17 05:05:49'),
(42, 58, 'confirmed', 'provider', 73, '', '2025-04-17 05:08:31'),
(43, 58, 'completed', 'provider', 73, '', '2025-04-17 05:08:37'),
(44, 58, 'cancelled', 'provider', 73, '', '2025-04-17 05:08:41'),
(45, 58, 'confirmed', 'provider', 73, '', '2025-04-17 05:08:46'),
(46, 58, 'completed', 'provider', 73, '', '2025-04-17 05:09:12'),
(47, 59, 'pending', 'customer', 70, 'Booking created', '2025-04-17 05:15:02'),
(48, 59, 'pending', 'provider', 73, '', '2025-04-17 05:15:33'),
(49, 59, 'confirmed', 'provider', 73, '', '2025-04-17 11:25:51'),
(50, 59, 'cancelled', 'provider', 73, '', '2025-04-17 11:26:11'),
(51, 60, 'pending', 'customer', 70, 'Booking created', '2025-04-17 11:59:42');

-- --------------------------------------------------------

--
-- Table structure for table `contact_messages`
--

DROP TABLE IF EXISTS `contact_messages`;
CREATE TABLE IF NOT EXISTS `contact_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `subject` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `message` text COLLATE utf8mb4_general_ci NOT NULL,
  `user_id` int DEFAULT NULL,
  `status` enum('new','read','responded') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'new',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `deletion_requests`
--

DROP TABLE IF EXISTS `deletion_requests`;
CREATE TABLE IF NOT EXISTS `deletion_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `status` enum('pending','processing','completed','cancelled') DEFAULT 'pending',
  `requested_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` timestamp NULL DEFAULT NULL,
  `notes` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `favorites`
--

DROP TABLE IF EXISTS `favorites`;
CREATE TABLE IF NOT EXISTS `favorites` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL,
  `provider_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_favorite` (`customer_id`,`provider_id`),
  KEY `provider_id` (`provider_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `favorites`
--

INSERT INTO `favorites` (`id`, `customer_id`, `provider_id`, `created_at`) VALUES
(6, 70, 35, '2025-04-17 12:18:07');

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

DROP TABLE IF EXISTS `messages`;
CREATE TABLE IF NOT EXISTS `messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sender_id` int NOT NULL,
  `receiver_id` int NOT NULL,
  `booking_id` int DEFAULT NULL,
  `message` text COLLATE utf8mb4_general_ci NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `quote_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sender_id` (`sender_id`),
  KEY `receiver_id` (`receiver_id`),
  KEY `booking_id` (`booking_id`)
) ENGINE=InnoDB AUTO_INCREMENT=119 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`id`, `sender_id`, `receiver_id`, `booking_id`, `message`, `is_read`, `created_at`, `quote_id`) VALUES
(114, 70, 73, 58, 'اهلا', 1, '2025-04-17 04:56:29', NULL),
(115, 73, 70, 58, 'هلا', 1, '2025-04-17 04:56:36', NULL),
(116, 73, 70, 59, 'hi', 1, '2025-04-17 05:15:37', NULL),
(117, 70, 73, 59, 'hi', 1, '2025-04-17 05:15:44', NULL),
(118, 70, 73, NULL, 'hi', 1, '2025-04-17 12:24:01', NULL);

--
-- Triggers `messages`
--
DROP TRIGGER IF EXISTS `message_notification`;
DELIMITER $$
CREATE TRIGGER `message_notification` AFTER INSERT ON `messages` FOR EACH ROW BEGIN
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
    
    -- If receiver is a provider, add notification
    IF EXISTS (SELECT 1 FROM providers p WHERE p.user_id = NEW.receiver_id) THEN
        INSERT INTO notifications (provider_id, user_id, type, reference_id, message)
        VALUES (
            (SELECT p.id FROM providers p WHERE p.user_id = NEW.receiver_id),
            NEW.receiver_id,
            'message',
            NEW.id,
            'لديك رسالة جديدة'
        );
    END IF;
    
    -- If receiver is a customer, add notification
    IF EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.receiver_id AND u.role = 'customer') THEN
        INSERT INTO notifications (user_id, type, reference_id, message)
        VALUES (
            NEW.receiver_id,
            'message',
            NEW.id,
            'لديك رسالة جديدة'
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `provider_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `customer_id` int DEFAULT NULL,
  `device_type` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `issue_description` text COLLATE utf8mb4_general_ci,
  `type` enum('booking','message','review','quote_request') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `reference_id` int NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `details` text COLLATE utf8mb4_general_ci,
  `is_read` tinyint(1) DEFAULT '0',
  `status` enum('pending','sent','failed') COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `received_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NULL DEFAULT NULL,
  `is_expired` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `provider_id` (`provider_id`),
  KEY `idx_provider_status` (`provider_id`,`status`,`is_read`),
  KEY `idx_notifications_user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1472 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `provider_id`, `user_id`, `customer_id`, `device_type`, `issue_description`, `type`, `reference_id`, `message`, `details`, `is_read`, `status`, `created_at`, `received_at`, `expires_at`, `is_expired`) VALUES
(1368, 32, NULL, 70, 'gaming', 'ؤؤ', 'quote_request', 160, 'طلب تسعير جديد لإصلاح gaming', NULL, 0, 'pending', '2025-04-15 20:45:39', '2025-04-15 20:52:52', NULL, 0),
(1369, 33, NULL, 70, 'gaming', 'ؤؤ', 'quote_request', 160, 'طلب تسعير جديد لإصلاح gaming', NULL, 0, 'pending', '2025-04-15 20:45:39', '2025-04-15 20:52:52', NULL, 0),
(1370, 34, NULL, 70, 'gaming', 'ؤؤ', 'quote_request', 160, 'طلب تسعير جديد لإصلاح gaming', NULL, 0, 'pending', '2025-04-15 20:45:39', '2025-04-15 20:52:52', NULL, 0),
(1371, 35, NULL, 70, 'gaming', 'ؤؤ', 'quote_request', 160, 'طلب تسعير جديد لإصلاح gaming', NULL, 0, 'pending', '2025-04-15 20:45:39', '2025-04-15 20:52:52', NULL, 0),
(1375, 32, NULL, 70, 'tablet', 'مم', 'quote_request', 161, 'طلب تسعير جديد لإصلاح tablet', NULL, 0, 'pending', '2025-04-15 20:46:12', '2025-04-15 20:52:52', NULL, 0),
(1376, 33, NULL, 70, 'tablet', 'مم', 'quote_request', 161, 'طلب تسعير جديد لإصلاح tablet', NULL, 1, 'pending', '2025-04-15 20:46:12', '2025-04-15 20:52:52', NULL, 0),
(1377, 34, NULL, 70, 'tablet', 'مم', 'quote_request', 161, 'طلب تسعير جديد لإصلاح tablet', NULL, 0, 'pending', '2025-04-15 20:46:12', '2025-04-15 20:52:52', NULL, 0),
(1378, 35, NULL, 70, 'tablet', 'مم', 'quote_request', 161, 'طلب تسعير جديد لإصلاح tablet', NULL, 1, 'pending', '2025-04-15 20:46:12', '2025-04-15 20:52:52', NULL, 0),
(1382, 32, NULL, 70, 'tablet', 'ccc', 'quote_request', 162, 'طلب تسعير جديد لإصلاح tablet', NULL, 0, 'pending', '2025-04-16 08:01:06', '2025-04-16 08:01:06', NULL, 0),
(1383, 33, NULL, 70, 'tablet', 'ccc', 'quote_request', 162, 'طلب تسعير جديد لإصلاح tablet', NULL, 1, 'pending', '2025-04-16 08:01:06', '2025-04-16 08:01:06', NULL, 0),
(1384, 34, NULL, 70, 'tablet', 'ccc', 'quote_request', 162, 'طلب تسعير جديد لإصلاح tablet', NULL, 0, 'pending', '2025-04-16 08:01:06', '2025-04-16 08:01:06', NULL, 0),
(1385, 35, NULL, 70, 'tablet', 'ccc', 'quote_request', 162, 'طلب تسعير جديد لإصلاح tablet', NULL, 1, 'pending', '2025-04-16 08:01:06', '2025-04-16 08:01:06', NULL, 0),
(1389, 32, NULL, 70, 'desktop', 'dd', 'quote_request', 163, 'طلب تسعير جديد لإصلاح desktop', NULL, 0, 'pending', '2025-04-16 08:01:28', '2025-04-16 08:01:28', NULL, 0),
(1390, 33, NULL, 70, 'desktop', 'dd', 'quote_request', 163, 'طلب تسعير جديد لإصلاح desktop', NULL, 0, 'pending', '2025-04-16 08:01:28', '2025-04-16 08:01:28', NULL, 0),
(1391, 34, NULL, 70, 'desktop', 'dd', 'quote_request', 163, 'طلب تسعير جديد لإصلاح desktop', NULL, 0, 'pending', '2025-04-16 08:01:28', '2025-04-16 08:01:28', NULL, 0),
(1392, 35, NULL, 70, 'desktop', 'dd', 'quote_request', 163, 'طلب تسعير جديد لإصلاح desktop', NULL, 0, 'pending', '2025-04-16 08:01:28', '2025-04-16 08:01:28', NULL, 0),
(1396, 32, NULL, 70, 'tablet', 'fff', 'quote_request', 164, 'طلب تسعير جديد لإصلاح tablet', NULL, 0, 'pending', '2025-04-16 09:56:11', '2025-04-16 09:56:11', NULL, 0),
(1397, 33, NULL, 70, 'tablet', 'fff', 'quote_request', 164, 'طلب تسعير جديد لإصلاح tablet', NULL, 1, 'pending', '2025-04-16 09:56:11', '2025-04-16 09:56:11', NULL, 0),
(1398, 34, NULL, 70, 'tablet', 'fff', 'quote_request', 164, 'طلب تسعير جديد لإصلاح tablet', NULL, 0, 'pending', '2025-04-16 09:56:11', '2025-04-16 09:56:11', NULL, 0),
(1399, 35, NULL, 70, 'tablet', 'fff', 'quote_request', 164, 'طلب تسعير جديد لإصلاح tablet', NULL, 1, 'pending', '2025-04-16 09:56:11', '2025-04-16 09:56:11', NULL, 0),
(1403, 32, NULL, 70, 'smartphone', 'ggt', 'quote_request', 165, 'طلب تسعير جديد لإصلاح smartphone', NULL, 0, 'pending', '2025-04-16 09:56:30', '2025-04-16 09:56:30', NULL, 0),
(1404, 33, NULL, 70, 'smartphone', 'ggt', 'quote_request', 165, 'طلب تسعير جديد لإصلاح smartphone', NULL, 0, 'pending', '2025-04-16 09:56:30', '2025-04-16 09:56:30', NULL, 0),
(1405, 34, NULL, 70, 'smartphone', 'ggt', 'quote_request', 165, 'طلب تسعير جديد لإصلاح smartphone', NULL, 0, 'pending', '2025-04-16 09:56:30', '2025-04-16 09:56:30', NULL, 0),
(1406, 35, NULL, 70, 'smartphone', 'ggt', 'quote_request', 165, 'طلب تسعير جديد لإصلاح smartphone', NULL, 0, 'pending', '2025-04-16 09:56:30', '2025-04-16 09:56:30', NULL, 0),
(1407, 32, NULL, 70, 'tablet', 'xx', 'quote_request', 166, 'طلب تسعير جديد لإصلاح tablet', NULL, 0, 'pending', '2025-04-16 13:18:35', '2025-04-16 13:18:35', NULL, 0),
(1408, 33, NULL, 70, 'tablet', 'xx', 'quote_request', 166, 'طلب تسعير جديد لإصلاح tablet', NULL, 1, 'pending', '2025-04-16 13:18:35', '2025-04-16 13:18:35', NULL, 0),
(1409, 34, NULL, 70, 'tablet', 'xx', 'quote_request', 166, 'طلب تسعير جديد لإصلاح tablet', NULL, 0, 'pending', '2025-04-16 13:18:35', '2025-04-16 13:18:35', NULL, 0),
(1410, 35, NULL, 70, 'tablet', 'xx', 'quote_request', 166, 'طلب تسعير جديد لإصلاح tablet', NULL, 1, 'pending', '2025-04-16 13:18:35', '2025-04-16 13:18:35', NULL, 0),
(1416, 32, NULL, 70, 'smartphone', 'ccc', 'quote_request', 167, 'طلب تسعير جديد لإصلاح smartphone', NULL, 0, 'pending', '2025-04-16 20:01:27', '2025-04-16 20:01:27', NULL, 0),
(1417, 33, NULL, 70, 'smartphone', 'ccc', 'quote_request', 167, 'طلب تسعير جديد لإصلاح smartphone', NULL, 0, 'pending', '2025-04-16 20:01:27', '2025-04-16 20:01:27', NULL, 0),
(1418, 34, NULL, 70, 'smartphone', 'ccc', 'quote_request', 167, 'طلب تسعير جديد لإصلاح smartphone', NULL, 0, 'pending', '2025-04-16 20:01:27', '2025-04-16 20:01:27', NULL, 0),
(1419, 35, NULL, 70, 'smartphone', 'ccc', 'quote_request', 167, 'طلب تسعير جديد لإصلاح smartphone', NULL, 0, 'pending', '2025-04-16 20:01:27', '2025-04-16 20:01:27', NULL, 0),
(1423, 32, NULL, 70, 'tablet', 'vv', 'quote_request', 168, 'طلب تسعير جديد لإصلاح tablet', NULL, 0, 'pending', '2025-04-16 20:01:44', '2025-04-16 20:01:44', NULL, 0),
(1424, 33, NULL, 70, 'tablet', 'vv', 'quote_request', 168, 'طلب تسعير جديد لإصلاح tablet', NULL, 0, 'pending', '2025-04-16 20:01:44', '2025-04-16 20:01:44', NULL, 0),
(1425, 34, NULL, 70, 'tablet', 'vv', 'quote_request', 168, 'طلب تسعير جديد لإصلاح tablet', NULL, 0, 'pending', '2025-04-16 20:01:44', '2025-04-16 20:01:44', NULL, 0),
(1426, 35, NULL, 70, 'tablet', 'vv', 'quote_request', 168, 'طلب تسعير جديد لإصلاح tablet', NULL, 1, 'pending', '2025-04-16 20:01:44', '2025-04-16 20:01:44', NULL, 0),
(1436, 35, NULL, NULL, NULL, NULL, 'booking', 58, 'لديك حجز جديد', NULL, 0, 'pending', '2025-04-17 04:55:38', '2025-04-17 04:55:38', NULL, 0),
(1437, 35, 73, NULL, NULL, NULL, 'message', 114, 'لديك رسالة جديدة', NULL, 0, 'pending', '2025-04-17 04:56:29', '2025-04-17 04:56:29', NULL, 0),
(1439, 32, NULL, 70, 'smartphone', 'ررر', 'quote_request', 169, 'طلب تسعير جديد لإصلاح smartphone', NULL, 0, 'pending', '2025-04-17 05:10:07', '2025-04-17 05:10:07', NULL, 0),
(1440, 33, NULL, 70, 'smartphone', 'ررر', 'quote_request', 169, 'طلب تسعير جديد لإصلاح smartphone', NULL, 0, 'pending', '2025-04-17 05:10:07', '2025-04-17 05:10:07', NULL, 0),
(1441, 34, NULL, 70, 'smartphone', 'ررر', 'quote_request', 169, 'طلب تسعير جديد لإصلاح smartphone', NULL, 0, 'pending', '2025-04-17 05:10:07', '2025-04-17 05:10:07', NULL, 0),
(1442, 35, NULL, 70, 'smartphone', 'ررر', 'quote_request', 169, 'طلب تسعير جديد لإصلاح smartphone', NULL, 0, 'pending', '2025-04-17 05:10:07', '2025-04-17 05:10:07', NULL, 0),
(1446, 32, NULL, 70, 'tablet', 'ررر', 'quote_request', 170, 'طلب تسعير جديد لإصلاح tablet', NULL, 0, 'pending', '2025-04-17 05:10:21', '2025-04-17 05:10:21', NULL, 0),
(1447, 33, NULL, 70, 'tablet', 'ررر', 'quote_request', 170, 'طلب تسعير جديد لإصلاح tablet', NULL, 0, 'pending', '2025-04-17 05:10:21', '2025-04-17 05:10:21', NULL, 0),
(1448, 34, NULL, 70, 'tablet', 'ررر', 'quote_request', 170, 'طلب تسعير جديد لإصلاح tablet', NULL, 0, 'pending', '2025-04-17 05:10:21', '2025-04-17 05:10:21', NULL, 0),
(1449, 35, NULL, 70, 'tablet', 'ررر', 'quote_request', 170, 'طلب تسعير جديد لإصلاح tablet', NULL, 1, 'pending', '2025-04-17 05:10:21', '2025-04-17 05:10:21', NULL, 0),
(1453, 35, NULL, NULL, NULL, NULL, 'booking', 59, 'لديك حجز جديد', NULL, 0, 'pending', '2025-04-17 05:15:02', '2025-04-17 05:15:02', NULL, 0),
(1455, 35, 73, NULL, NULL, NULL, 'message', 117, 'لديك رسالة جديدة', NULL, 0, 'pending', '2025-04-17 05:15:44', '2025-04-17 05:15:44', NULL, 0),
(1456, 35, NULL, NULL, NULL, NULL, 'booking', 60, 'لديك حجز جديد', NULL, 0, 'pending', '2025-04-17 11:59:42', '2025-04-17 11:59:42', NULL, 0),
(1457, 32, NULL, 70, 'smartphone', 'bbb', 'quote_request', 171, 'طلب تسعير جديد لإصلاح smartphone', NULL, 0, 'pending', '2025-04-17 12:03:13', '2025-04-17 12:03:13', NULL, 0),
(1458, 33, NULL, 70, 'smartphone', 'bbb', 'quote_request', 171, 'طلب تسعير جديد لإصلاح smartphone', NULL, 0, 'pending', '2025-04-17 12:03:13', '2025-04-17 12:03:13', NULL, 0),
(1459, 34, NULL, 70, 'smartphone', 'bbb', 'quote_request', 171, 'طلب تسعير جديد لإصلاح smartphone', NULL, 0, 'pending', '2025-04-17 12:03:13', '2025-04-17 12:03:13', NULL, 0),
(1460, 35, NULL, 70, 'smartphone', 'bbb', 'quote_request', 171, 'طلب تسعير جديد لإصلاح smartphone', NULL, 0, 'pending', '2025-04-17 12:03:13', '2025-04-17 12:03:13', NULL, 0),
(1464, 32, NULL, 70, 'smartphone', 'bbb', 'quote_request', 172, 'طلب تسعير جديد لإصلاح smartphone', NULL, 0, 'pending', '2025-04-17 12:04:42', '2025-04-17 12:04:42', NULL, 0),
(1465, 33, NULL, 70, 'smartphone', 'bbb', 'quote_request', 172, 'طلب تسعير جديد لإصلاح smartphone', NULL, 0, 'pending', '2025-04-17 12:04:42', '2025-04-17 12:04:42', NULL, 0),
(1466, 34, NULL, 70, 'smartphone', 'bbb', 'quote_request', 172, 'طلب تسعير جديد لإصلاح smartphone', NULL, 0, 'pending', '2025-04-17 12:04:42', '2025-04-17 12:04:42', NULL, 0),
(1467, 35, NULL, 70, 'smartphone', 'bbb', 'quote_request', 172, 'طلب تسعير جديد لإصلاح smartphone', NULL, 0, 'pending', '2025-04-17 12:04:42', '2025-04-17 12:04:42', NULL, 0),
(1471, 35, 73, NULL, NULL, NULL, 'message', 118, 'لديك رسالة جديدة', NULL, 0, 'pending', '2025-04-17 12:24:01', '2025-04-17 12:24:01', NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `notification_logs`
--

DROP TABLE IF EXISTS `notification_logs`;
CREATE TABLE IF NOT EXISTS `notification_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `action` varchar(50) DEFAULT NULL,
  `error_message` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=79 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `notification_logs`
--

INSERT INTO `notification_logs` (`id`, `action`, `error_message`, `created_at`) VALUES
(66, 'create_quote_notifications_success', 'تم إنشاء الطلب رقم: 160', '2025-04-15 20:45:39'),
(67, 'create_quote_notifications_success', 'تم إنشاء الطلب رقم: 161', '2025-04-15 20:46:12'),
(68, 'create_quote_notifications_success', 'تم إنشاء الطلب رقم: 162', '2025-04-16 08:01:06'),
(69, 'create_quote_notifications_success', 'تم إنشاء الطلب رقم: 163', '2025-04-16 08:01:28'),
(70, 'create_quote_notifications_success', 'تم إنشاء الطلب رقم: 164', '2025-04-16 09:56:11'),
(71, 'create_quote_notifications_success', 'تم إنشاء الطلب رقم: 165', '2025-04-16 09:56:30'),
(72, 'create_quote_notifications_success', 'تم إنشاء الطلب رقم: 166', '2025-04-16 13:18:35'),
(73, 'create_quote_notifications_success', 'تم إنشاء الطلب رقم: 167', '2025-04-16 20:01:27'),
(74, 'create_quote_notifications_success', 'تم إنشاء الطلب رقم: 168', '2025-04-16 20:01:44'),
(75, 'create_quote_notifications_success', 'تم إنشاء الطلب رقم: 169', '2025-04-17 05:10:07'),
(76, 'create_quote_notifications_success', 'تم إنشاء الطلب رقم: 170', '2025-04-17 05:10:21'),
(77, 'create_quote_notifications_success', 'تم إنشاء الطلب رقم: 171', '2025-04-17 12:03:13'),
(78, 'create_quote_notifications_success', 'تم إنشاء الطلب رقم: 172', '2025-04-17 12:04:42');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiry_date` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `token` (`token`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
CREATE TABLE IF NOT EXISTS `payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL,
  `quote_id` int NOT NULL,
  `request_id` int NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_ref` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','processing','paid','failed','refunded') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `payment_method` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'bank_transfer',
  `transaction_details` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`),
  KEY `quote_id` (`quote_id`),
  KEY `request_id` (`request_id`),
  KEY `idx_customer_status` (`customer_id`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `providers`
--

DROP TABLE IF EXISTS `providers`;
CREATE TABLE IF NOT EXISTS `providers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `specialties` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `experience` varchar(10) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `hourly_rate` decimal(10,2) DEFAULT '0.00',
  `bio` text COLLATE utf8mb4_general_ci,
  `location` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `availability` text COLLATE utf8mb4_general_ci,
  `is_verified` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `response_time` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `education` text COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `providers`
--

INSERT INTO `providers` (`id`, `user_id`, `specialties`, `experience`, `hourly_rate`, `bio`, `location`, `availability`, `is_verified`, `created_at`, `updated_at`, `response_time`, `education`) VALUES
(32, 69, 'laptop', '0-1', 0.00, 'qqqqqq', 'Al Bahah', NULL, 1, '2025-04-03 18:06:07', '2025-04-13 12:57:47', NULL, ''),
(33, 71, 'tablet', '3-5', 0.00, 'vvv', 'Al Jubail', NULL, 1, '2025-04-04 17:26:56', '2025-04-04 17:26:56', NULL, 'bbb'),
(34, 72, 'laptop,tv', '0-1', 0.00, 'mmmm', 'Al Lith', NULL, 1, '2025-04-06 03:59:54', '2025-04-07 07:16:08', NULL, 'llll'),
(35, 73, 'tablet,tv', '3-5', 0.00, 'ؤؤ', 'Ad Dilam', NULL, 0, '2025-04-07 15:54:15', '2025-04-13 13:16:56', NULL, 'يي');

-- --------------------------------------------------------

--
-- Table structure for table `provider_time_off`
--

DROP TABLE IF EXISTS `provider_time_off`;
CREATE TABLE IF NOT EXISTS `provider_time_off` (
  `id` int NOT NULL AUTO_INCREMENT,
  `provider_id` int NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `all_day` tinyint(1) DEFAULT '1',
  `start_time` time DEFAULT '00:00:00',
  `end_time` time DEFAULT '23:59:59',
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `provider_id` (`provider_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `provider_working_hours`
--

DROP TABLE IF EXISTS `provider_working_hours`;
CREATE TABLE IF NOT EXISTS `provider_working_hours` (
  `id` int NOT NULL AUTO_INCREMENT,
  `provider_id` int NOT NULL,
  `day_of_week` tinyint NOT NULL COMMENT '0=Sunday, 1=Monday, etc.',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `provider_id` (`provider_id`)
) ENGINE=InnoDB AUTO_INCREMENT=134 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `provider_working_hours`
--

INSERT INTO `provider_working_hours` (`id`, `provider_id`, `day_of_week`, `start_time`, `end_time`, `is_available`, `created_at`, `updated_at`) VALUES
(127, 35, 0, '00:00:00', '00:00:00', 0, '2025-04-17 12:13:47', '2025-04-17 12:13:47'),
(128, 35, 1, '00:00:00', '00:00:00', 1, '2025-04-17 12:13:47', '2025-04-17 12:13:47'),
(129, 35, 2, '00:00:00', '00:00:00', 1, '2025-04-17 12:13:47', '2025-04-17 12:13:47'),
(130, 35, 3, '00:00:00', '00:00:00', 0, '2025-04-17 12:13:47', '2025-04-17 12:13:47'),
(131, 35, 4, '00:00:00', '00:00:00', 0, '2025-04-17 12:13:47', '2025-04-17 12:13:47'),
(132, 35, 5, '00:00:00', '00:00:00', 0, '2025-04-17 12:13:47', '2025-04-17 12:13:47'),
(133, 35, 6, '00:00:00', '00:00:00', 0, '2025-04-17 12:13:47', '2025-04-17 12:13:47');

-- --------------------------------------------------------

--
-- Table structure for table `quotes`
--

DROP TABLE IF EXISTS `quotes`;
CREATE TABLE IF NOT EXISTS `quotes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `request_id` int NOT NULL,
  `technician_id` int NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `estimated_time` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','accepted','rejected','completed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `provider_id` int DEFAULT NULL,
  `warranty` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'Standard warranty',
  `parts_needed` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  KEY `technician_id` (`technician_id`)
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quote_notifications`
--

DROP TABLE IF EXISTS `quote_notifications`;
CREATE TABLE IF NOT EXISTS `quote_notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `recipient_id` int NOT NULL,
  `request_id` int NOT NULL,
  `type` enum('new_request','new_quote','quote_accepted','quote_rejected','repair_completed') COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  KEY `idx_recipient_read` (`recipient_id`,`is_read`)
) ENGINE=InnoDB AUTO_INCREMENT=177 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quote_requests`
--

DROP TABLE IF EXISTS `quote_requests`;
CREATE TABLE IF NOT EXISTS `quote_requests` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL,
  `device_type` enum('smartphone','laptop','tablet','desktop','gaming','tv') COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_brand` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'Not specified',
  `device_model` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'Not specified',
  `device_condition` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'good',
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT 'Not specified',
  `urgency` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'medium',
  `service_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'repair',
  `additional_info` text COLLATE utf8mb4_unicode_ci,
  `issue_description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','quoted','accepted','completed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=173 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quote_requests`
--

INSERT INTO `quote_requests` (`id`, `customer_id`, `device_type`, `device_brand`, `device_model`, `device_condition`, `location`, `urgency`, `service_type`, `additional_info`, `issue_description`, `status`, `created_at`, `updated_at`) VALUES
(172, 70, 'smartphone', 'jjj', 'nnn', 'good', 'Not specified', 'medium', 'repair', 'vvv', 'bbb', 'pending', '2025-04-17 12:04:42', '2025-04-17 12:04:42');

-- --------------------------------------------------------

--
-- Table structure for table `quote_request_media`
--

DROP TABLE IF EXISTS `quote_request_media`;
CREATE TABLE IF NOT EXISTS `quote_request_media` (
  `id` int NOT NULL AUTO_INCREMENT,
  `request_id` int NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `media_type` enum('image','video') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`)
) ENGINE=InnoDB AUTO_INCREMENT=45 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quote_request_media`
--

INSERT INTO `quote_request_media` (`id`, `request_id`, `file_name`, `original_name`, `file_path`, `file_type`, `media_type`, `created_at`) VALUES
(44, 172, '6800ee5ac48fd_Screenshot 2025-02-12 120644.png', 'Screenshot 2025-02-12 120644.png', 'uploads/request_images/6800ee5ac48fd_Screenshot 2025-02-12 120644.png', 'image/png', 'image', '2025-04-17 12:04:42');

-- --------------------------------------------------------

--
-- Table structure for table `repairs`
--

DROP TABLE IF EXISTS `repairs`;
CREATE TABLE IF NOT EXISTS `repairs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL,
  `device_id` int DEFAULT NULL,
  `device_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `device_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `issue_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `technician_id` int DEFAULT NULL,
  `quote_id` int DEFAULT NULL,
  `status` enum('pending','scheduled','in_progress','awaiting_parts','completed','cancelled','on_hold') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `cancellation_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `estimated_completion` date DEFAULT NULL,
  `completion_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`),
  KEY `device_id` (`device_id`),
  KEY `technician_id` (`technician_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `repairs`
--
DROP TRIGGER IF EXISTS `repair_notification`;
DELIMITER $$
CREATE TRIGGER `repair_notification` AFTER INSERT ON `repairs` FOR EACH ROW BEGIN
    INSERT INTO notifications (customer_id, type, reference_id, message, device_type, status)
    VALUES (
        NEW.customer_id,
        'repair_created',
        NEW.id,
        CONCAT('Your repair request for ', COALESCE(NEW.device_name, 'your device'), ' has been received.'),
        NEW.device_type,
        'pending'
    );
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `repair_status_history`;
DELIMITER $$
CREATE TRIGGER `repair_status_history` AFTER UPDATE ON `repairs` FOR EACH ROW BEGIN
    IF OLD.status <> NEW.status THEN
        INSERT INTO repair_history (repair_id, status, notes, created_by)
        VALUES (NEW.id, NEW.status, CONCAT('Status changed from ', OLD.status, ' to ', NEW.status), NEW.customer_id);
    END IF;
END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `repair_status_notification`;
DELIMITER $$
CREATE TRIGGER `repair_status_notification` AFTER UPDATE ON `repairs` FOR EACH ROW BEGIN
    IF OLD.status <> NEW.status THEN
        INSERT INTO notifications (customer_id, type, reference_id, message, device_type, status)
        VALUES (
            NEW.customer_id,
            'repair_update',
            NEW.id,
            CONCAT('Your repair for ', COALESCE(NEW.device_name, 'your device'), ' status has been updated to ', NEW.status, '.'),
            NEW.device_type,
            'pending'
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `repair_history`
--

DROP TABLE IF EXISTS `repair_history`;
CREATE TABLE IF NOT EXISTS `repair_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `repair_id` int NOT NULL,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `repair_id` (`repair_id`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

DROP TABLE IF EXISTS `reviews`;
CREATE TABLE IF NOT EXISTS `reviews` (
  `id` int NOT NULL AUTO_INCREMENT,
  `booking_id` int NOT NULL,
  `customer_id` int NOT NULL,
  `provider_id` int NOT NULL,
  `rating` decimal(2,1) NOT NULL,
  `comment` text COLLATE utf8mb4_general_ci,
  `is_published` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `booking_id` (`booking_id`),
  KEY `customer_id` (`customer_id`),
  KEY `provider_id` (`provider_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `schedule_slots`
--

DROP TABLE IF EXISTS `schedule_slots`;
CREATE TABLE IF NOT EXISTS `schedule_slots` (
  `id` int NOT NULL AUTO_INCREMENT,
  `provider_id` int NOT NULL,
  `date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `max_appointments` int NOT NULL DEFAULT '1',
  `description` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `provider_id` (`provider_id`)
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

DROP TABLE IF EXISTS `services`;
CREATE TABLE IF NOT EXISTS `services` (
  `id` int NOT NULL AUTO_INCREMENT,
  `provider_id` int NOT NULL,
  `category` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `duration` int DEFAULT '60',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_by_provider` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `provider_id` (`provider_id`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`id`, `provider_id`, `category`, `name`, `description`, `price`, `duration`, `is_active`, `created_at`, `updated_at`, `deleted_by_provider`) VALUES
(21, 32, 'laptop', 'zzzz', 'cccc', 12.00, 30, 0, '2025-04-05 15:08:22', '2025-04-10 17:31:59', 1),
(22, 32, 'gaming', 'اصلاح شاشات ايفون 15 العادي و برو', 'تستتستت', 520.00, 30, 0, '2025-04-10 17:32:50', '2025-04-12 18:39:21', 0),
(23, 32, 'laptop', 'ggg', 'dddd`', 22.00, 45, 1, '2025-04-12 19:50:18', '2025-04-12 19:50:49', 1),
(24, 32, 'laptop', 'jjj', 'gg', 22.00, 15, 1, '2025-04-12 19:50:29', '2025-04-12 19:50:52', 1),
(25, 35, 'tablet', 'تتت', 'ككك', 630.00, 30, 1, '2025-04-13 14:09:36', '2025-04-17 12:37:55', 0),
(26, 35, 'tablet', 'gggggg', 'vvvvvv', 56.00, 45, 1, '2025-04-17 05:13:15', '2025-04-17 12:07:42', 0);

-- --------------------------------------------------------

--
-- Table structure for table `service_deletion_logs`
--

DROP TABLE IF EXISTS `service_deletion_logs`;
CREATE TABLE IF NOT EXISTS `service_deletion_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `service_id` int NOT NULL,
  `provider_id` int NOT NULL,
  `deleted_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `had_bookings` tinyint(1) DEFAULT '0',
  `restored_by_admin` int DEFAULT NULL,
  `restored_at` timestamp NULL DEFAULT NULL,
  `restore_notes` text COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`id`),
  KEY `service_id` (`service_id`),
  KEY `provider_id` (`provider_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `service_deletion_logs`
--

INSERT INTO `service_deletion_logs` (`id`, `service_id`, `provider_id`, `deleted_at`, `had_bookings`, `restored_by_admin`, `restored_at`, `restore_notes`) VALUES
(3, 21, 32, '2025-04-10 17:31:59', 1, NULL, NULL, NULL),
(4, 23, 32, '2025-04-12 19:50:49', 0, NULL, NULL, NULL),
(5, 24, 32, '2025-04-12 19:50:52', 0, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `role` enum('admin','provider','customer') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'customer',
  `first_name` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `last_name` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `profile_image` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=74 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `role`, `first_name`, `last_name`, `phone`, `profile_image`, `status`, `created_at`, `updated_at`) VALUES
(68, 'admin', 'admin@ad.com', '$2y$10$dydws9OBxGZx9Gnz.iiU3ePOSav8DaJasfZoIede7CDbVdA7rmq.O', 'admin', 'tri', 'gh', '12212222', '', 'active', '2025-04-03 18:03:39', '2025-04-03 18:03:49'),
(69, 'tri', 'tri@gh.com', '$2y$10$w0X43B5ZTByrvWUt6x47yuoa5C9wF7.xOqa6Wle6bI1azsnPN9LaK', 'provider', 'Tri', 'gha', '222222222222222', '67fad115cb660.png', 'active', '2025-04-03 18:06:07', '2025-04-12 20:46:33'),
(70, 'tui', 'turke100200@gmail.com', '$2y$10$QXSC1i0epijGr3Bpgn0yu.UVrjFBxLAjywbdi.QEud4we5HFON1GW', 'customer', 'tri', 'ghamdi', '1111111111', '', 'active', '2025-04-03 18:06:50', '2025-04-11 08:44:47'),
(71, 'aaa', 'a@gg.com', '$2y$10$D3HdtFdkamePyq.5jaOWRO3e2D5GygeXYLx.paFbQqw1xZTmSBPVq', 'provider', 'asaa', 'fff', '22312121333', '', 'active', '2025-04-04 17:26:56', '2025-04-04 17:26:56'),
(72, 'yty', 'y@gm.com', '$2y$10$3u/IwVgJ7RY45asVYfATd.1X0mjbFPsySV35DRBuvUsEPpsUBF1Su', 'provider', 'yyy', 'iii', '888888888888888888', '', 'active', '2025-04-06 03:59:54', '2025-04-06 03:59:54'),
(73, 'tri707', 'as@gg.com', '$2y$10$jhuIwEOG/LuglTElBYrnDe0465H/aCk/tQEvNbCAf/ZyaKhB4f/Tq', 'provider', 'hhhhh', 'ccccc', '33133241515', NULL, 'active', '2025-04-07 15:54:15', '2025-04-07 15:54:15');

-- --------------------------------------------------------

--
-- Table structure for table `user_settings`
--

DROP TABLE IF EXISTS `user_settings`;
CREATE TABLE IF NOT EXISTS `user_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `email_notifications` tinyint(1) DEFAULT '1',
  `sms_notifications` tinyint(1) DEFAULT '0',
  `push_notifications` tinyint(1) DEFAULT '1',
  `quote_notifications` tinyint(1) DEFAULT '1',
  `booking_notifications` tinyint(1) DEFAULT '1',
  `repair_notifications` tinyint(1) DEFAULT '1',
  `marketing_emails` tinyint(1) DEFAULT '0',
  `language` varchar(10) DEFAULT 'en',
  `timezone` varchar(50) DEFAULT 'UTC',
  `currency` varchar(10) DEFAULT 'USD',
  `dark_mode` tinyint(1) DEFAULT '0',
  `high_contrast` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bookings_ibfk_3` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `contact_messages`
--
ALTER TABLE `contact_messages`
  ADD CONSTRAINT `contact_messages_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `favorites`
--
ALTER TABLE `favorites`
  ADD CONSTRAINT `favorites_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `favorites_ibfk_2` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_ibfk_3` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `providers`
--
ALTER TABLE `providers`
  ADD CONSTRAINT `providers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `provider_time_off`
--
ALTER TABLE `provider_time_off`
  ADD CONSTRAINT `provider_time_off_ibfk_1` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `provider_working_hours`
--
ALTER TABLE `provider_working_hours`
  ADD CONSTRAINT `working_hours_provider_fk` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quotes`
--
ALTER TABLE `quotes`
  ADD CONSTRAINT `quotes_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `quote_requests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `quotes_ibfk_2` FOREIGN KEY (`technician_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quote_notifications`
--
ALTER TABLE `quote_notifications`
  ADD CONSTRAINT `quote_notifications_ibfk_1` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `quote_notifications_ibfk_2` FOREIGN KEY (`request_id`) REFERENCES `quote_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quote_requests`
--
ALTER TABLE `quote_requests`
  ADD CONSTRAINT `quote_requests_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quote_request_media`
--
ALTER TABLE `quote_request_media`
  ADD CONSTRAINT `quote_request_media_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `quote_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `repairs`
--
ALTER TABLE `repairs`
  ADD CONSTRAINT `repairs_customer_fk` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `repairs_technician_fk` FOREIGN KEY (`technician_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `repair_history`
--
ALTER TABLE `repair_history`
  ADD CONSTRAINT `repair_history_repair_fk` FOREIGN KEY (`repair_id`) REFERENCES `repairs` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `repair_history_user_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `reviews_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reviews_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reviews_ibfk_3` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `schedule_slots`
--
ALTER TABLE `schedule_slots`
  ADD CONSTRAINT `schedule_slots_ibfk_1` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `services`
--
ALTER TABLE `services`
  ADD CONSTRAINT `services_ibfk_1` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
