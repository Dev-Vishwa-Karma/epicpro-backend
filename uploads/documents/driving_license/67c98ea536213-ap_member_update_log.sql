-- phpMyAdmin SQL Dump
-- version 4.9.5deb2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jan 18, 2025 at 11:09 AM
-- Server version: 8.0.40-0ubuntu0.20.04.1
-- PHP Version: 7.4.3-4ubuntu2.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `tools`
--

-- --------------------------------------------------------

--
-- Table structure for table `ap_member_update_log`
--

CREATE TABLE `ap_member_update_log` (
  `id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `product_id` int DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `status` tinyint NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `ap_member_update_log`
--

INSERT INTO `ap_member_update_log` (`id`, `user_id`, `product_id`, `start_date`, `end_date`, `status`, `created_at`) VALUES
(1, 2, 13, '2024-06-20', '9999-12-31', 1, '2025-01-17 06:27:09'),
(2, 2, 3, '2022-02-28', '2025-12-30', 1, '2025-01-17 06:27:26'),
(7, 3, 4, '2023-01-31', '2025-01-31', 0, '2025-01-17 07:51:58'),
(8, 4, 5, '2022-04-12', '2025-12-30', 1, '2025-01-17 07:55:39'),
(9, 5, 3, '2022-12-21', '2025-12-30', 1, '2025-01-17 09:47:21'),
(10, 5, 5, '2023-01-06', '2025-12-30', 1, '2025-01-17 09:49:49');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ap_member_update_log`
--
ALTER TABLE `ap_member_update_log`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `ap_member_update_log`
--
ALTER TABLE `ap_member_update_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
