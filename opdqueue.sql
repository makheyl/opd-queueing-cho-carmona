-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 10, 2025 at 12:49 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `opdqueue`
--

-- --------------------------------------------------------

--
-- Table structure for table `daily_statistics`
--

CREATE TABLE `daily_statistics` (
  `id` int(11) NOT NULL,
  `date` date NOT NULL,
  `table_number` int(11) NOT NULL,
  `doctor_name` varchar(100) NOT NULL,
  `patients_served` int(11) DEFAULT 0,
  `patients_pending` int(11) DEFAULT 0,
  `patients_cancelled` int(11) DEFAULT 0,
  `total_patients` int(11) DEFAULT 0,
  `last_updated` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `doctor_appointments_log`
--

CREATE TABLE `doctor_appointments_log` (
  `id` int(11) NOT NULL,
  `doctor_name` varchar(255) NOT NULL,
  `table_number` int(11) NOT NULL,
  `patient_queue_number` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `log_time` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `historical_data`
--

CREATE TABLE `historical_data` (
  `id` int(11) NOT NULL,
  `date` date NOT NULL,
  `table_number` int(11) NOT NULL,
  `doctor_name` varchar(100) NOT NULL,
  `patients_served` int(11) DEFAULT 0,
  `patients_pending` int(11) DEFAULT 0,
  `patients_cancelled` int(11) DEFAULT 0,
  `total_patients` int(11) DEFAULT 0,
  `exported_to_csv` tinyint(1) DEFAULT 0,
  `export_date` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `queue`
--

CREATE TABLE `queue` (
  `id` int(11) NOT NULL,
  `queue_number` int(11) NOT NULL,
  `status` enum('waiting','serving','served','pending','cancelled') DEFAULT 'waiting',
  `doctor_name` varchar(100) DEFAULT NULL,
  `table_number` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `served_at` datetime DEFAULT NULL,
  `priority` enum('no','yes','infectious','completed','came_back','follow_up') DEFAULT 'no'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `daily_statistics`
--
ALTER TABLE `daily_statistics`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_date_table_doctor` (`date`,`table_number`,`doctor_name`),
  ADD KEY `idx_date` (`date`),
  ADD KEY `idx_table_number` (`table_number`),
  ADD KEY `idx_doctor_name` (`doctor_name`);

--
-- Indexes for table `doctor_appointments_log`
--
ALTER TABLE `doctor_appointments_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `historical_data`
--
ALTER TABLE `historical_data`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_date_table_doctor` (`date`,`table_number`,`doctor_name`),
  ADD KEY `idx_date` (`date`),
  ADD KEY `idx_exported` (`exported_to_csv`);

--
-- Indexes for table `queue`
--
ALTER TABLE `queue`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `daily_statistics`
--
ALTER TABLE `daily_statistics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `doctor_appointments_log`
--
ALTER TABLE `doctor_appointments_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT for table `historical_data`
--
ALTER TABLE `historical_data`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `queue`
--
ALTER TABLE `queue`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=255;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
