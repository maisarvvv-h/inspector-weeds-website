-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 14, 2026 at 05:42 AM
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
-- Database: `inspector_weeds`
--

-- --------------------------------------------------------

--
-- Table structure for table `contact_messages`
--

CREATE TABLE `contact_messages` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(120) NOT NULL,
  `subject` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `detection_results`
--

CREATE TABLE `detection_results` (
  `id` int(11) NOT NULL,
  `image_id` varchar(120) NOT NULL,
  `image_file` varchar(255) NOT NULL,
  `zone_id` varchar(20) NOT NULL,
  `severity_level` enum('Low','High') NOT NULL,
  `weed_coverage` decimal(7,2) NOT NULL,
  `weed_pixels` bigint(20) UNSIGNED NOT NULL,
  `total_pixels` bigint(20) UNSIGNED NOT NULL,
  `average_confidence` decimal(7,2) NOT NULL,
  `processed_image_path` varchar(255) NOT NULL,
  `mask_image_path` varchar(255) NOT NULL,
  `model_used` varchar(100) DEFAULT 'DeepLabV3+',
  `manual_review_status` enum('Yes','No') DEFAULT 'No',
  `recommendation` text DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `detection_results`
--

INSERT INTO `detection_results` (`id`, `image_id`, `image_file`, `zone_id`, `severity_level`, `weed_coverage`, `weed_pixels`, `total_pixels`, `average_confidence`, `processed_image_path`, `mask_image_path`, `model_used`, `manual_review_status`, `recommendation`, `uploaded_at`) VALUES
(43, 'E23_jpg_rf_d2eee67d11e63edeb51caf2643cabada_20260801073211_9f1bdbc1', 'E23_jpg_rf_d2eee67d11e63edeb51caf2643cabada_20260801073211_9f1bdbc1.jpg', 'Zone E', 'Low', 5.53, 58007, 1048576, 98.76, 'processed/E23_jpg_rf_d2eee67d11e63edeb51caf2643cabada_20260801073211_9f1bdbc1_overlay.png', 'processed/E23_jpg_rf_d2eee67d11e63edeb51caf2643cabada_20260801073211_9f1bdbc1_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-01 05:32:44'),
(44, 'E22_jpg_rf_34a755feccb72fe43f9ab78b86a79c50_20260801073306_0dc4241f', 'E22_jpg_rf_34a755feccb72fe43f9ab78b86a79c50_20260801073306_0dc4241f.jpg', 'Zone E', 'Low', 11.67, 122367, 1048576, 98.86, 'processed/E22_jpg_rf_34a755feccb72fe43f9ab78b86a79c50_20260801073306_0dc4241f_overlay.png', 'processed/E22_jpg_rf_34a755feccb72fe43f9ab78b86a79c50_20260801073306_0dc4241f_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-01 05:33:38'),
(45, 'D47_jpg_rf_00bf65e16349a6818b4be171859ec720_20260801073351_d3117511', 'D47_jpg_rf_00bf65e16349a6818b4be171859ec720_20260801073351_d3117511.jpg', 'Zone D', 'Low', 2.46, 25846, 1048576, 95.98, 'processed/D47_jpg_rf_00bf65e16349a6818b4be171859ec720_20260801073351_d3117511_overlay.png', 'processed/D47_jpg_rf_00bf65e16349a6818b4be171859ec720_20260801073351_d3117511_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-01 05:34:23'),
(46, 'C54_jpg_rf_01f4cd81c7190f2b5097fbbd8ac6d458_20260801073444_99efa20d', 'C54_jpg_rf_01f4cd81c7190f2b5097fbbd8ac6d458_20260801073444_99efa20d.jpg', 'Zone C', 'Low', 9.05, 94905, 1048576, 98.44, 'processed/C54_jpg_rf_01f4cd81c7190f2b5097fbbd8ac6d458_20260801073444_99efa20d_overlay.png', 'processed/C54_jpg_rf_01f4cd81c7190f2b5097fbbd8ac6d458_20260801073444_99efa20d_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-01 05:35:15'),
(47, 'B1_jpg_rf_d62d8709040841c8fdf0ed2e7c5a8fca_20260801073539_4a2796f3', 'B1_jpg_rf_d62d8709040841c8fdf0ed2e7c5a8fca_20260801073539_4a2796f3.jpg', 'Zone B', 'Low', 6.33, 66414, 1048576, 97.90, 'processed/B1_jpg_rf_d62d8709040841c8fdf0ed2e7c5a8fca_20260801073539_4a2796f3_overlay.png', 'processed/B1_jpg_rf_d62d8709040841c8fdf0ed2e7c5a8fca_20260801073539_4a2796f3_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-01 05:36:18'),
(48, 'A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801073828_2cca193d', 'A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801073828_2cca193d.jpg', 'Zone A', 'High', 18.43, 193221, 1048576, 99.34, 'processed/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801073828_2cca193d_overlay.png', 'processed/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801073828_2cca193d_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-01 05:39:12'),
(53, 'A34_jpg_rf_8b4acac219c88fc1ce8a2e9548d5e693_20260811015617_5523ea5b', 'A34_jpg_rf_8b4acac219c88fc1ce8a2e9548d5e693_20260811015617_5523ea5b.jpg', 'Zone A', 'High', 11.76, 123349, 1048576, 98.56, 'processed/A34_jpg_rf_8b4acac219c88fc1ce8a2e9548d5e693_20260811015617_5523ea5b_overlay.png', 'processed/A34_jpg_rf_8b4acac219c88fc1ce8a2e9548d5e693_20260811015617_5523ea5b_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-10 23:57:51'),
(54, 'E26_20260811035216_77c90db0', 'E26_20260811035216_77c90db0.jpg', 'Zone E', 'Low', 4.88, 51124, 1048576, 95.27, 'processed/E26_20260811035216_77c90db0_overlay.png', 'processed/E26_20260811035216_77c90db0_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 01:52:46'),
(55, 'A10_20260811040717_28d38719', 'A10_20260811040717_28d38719.jpg', 'Zone A', 'High', 6.35, 66622, 1048576, 98.79, 'processed/A10_20260811040717_28d38719_overlay.png', 'processed/A10_20260811040717_28d38719_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 02:07:42'),
(56, 'A03_20260811041616_a09d1426', 'A03_20260811041616_a09d1426.jpg', 'Zone A', 'High', 10.90, 114288, 1048576, 98.49, 'processed/A03_20260811041616_a09d1426_overlay.png', 'processed/A03_20260811041616_a09d1426_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 02:16:38'),
(57, 'A13_20260811042029_c190ef19', 'A13_20260811042029_c190ef19.jpg', 'Zone A', 'Low', 4.08, 42730, 1048576, 88.04, 'processed/A13_20260811042029_c190ef19_overlay.png', 'processed/A13_20260811042029_c190ef19_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 02:20:52'),
(58, 'A22_20260811043104_bdf3bd45', 'A22_20260811043104_bdf3bd45.jpg', 'Zone A', 'Low', 4.82, 50505, 1048576, 98.03, 'processed/A22_20260811043104_bdf3bd45_overlay.png', 'processed/A22_20260811043104_bdf3bd45_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 02:31:26'),
(59, 'B09_20260811044940_8db198b5', 'B09_20260811044940_8db198b5.jpg', 'Zone B', 'Low', 3.79, 39749, 1048576, 97.73, 'processed/B09_20260811044940_8db198b5_overlay.png', 'processed/B09_20260811044940_8db198b5_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 02:51:08'),
(60, 'A05_20260811045836_3b0be20a', 'A05_20260811045836_3b0be20a.jpg', 'Zone A', 'High', 9.49, 99539, 1048576, 98.75, 'processed/A05_20260811045836_3b0be20a_overlay.png', 'processed/A05_20260811045836_3b0be20a_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 02:59:33'),
(61, 'A20_20260811050307_084c55ad', 'A20_20260811050307_084c55ad.jpg', 'Zone A', 'Low', 1.91, 20053, 1048576, 89.40, 'processed/A20_20260811050307_084c55ad_overlay.png', 'processed/A20_20260811050307_084c55ad_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 03:03:59'),
(62, 'B20_20260811051453_530d7644', 'B20_20260811051453_530d7644.jpg', 'Zone B', 'High', 9.43, 98869, 1048576, 94.53, 'processed/B20_20260811051453_530d7644_overlay.png', 'processed/B20_20260811051453_530d7644_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 03:15:48'),
(63, 'A05_20260811051838_2442bd79', 'A05_20260811051838_2442bd79.jpg', 'Zone A', 'High', 9.49, 99539, 1048576, 98.75, 'processed/A05_20260811051838_2442bd79_overlay.png', 'processed/A05_20260811051838_2442bd79_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 03:19:35'),
(65, 'E22_20260811053002_6f4784bc', 'E22_20260811053002_6f4784bc.jpg', 'Zone E', 'High', 11.98, 125649, 1048576, 96.79, 'processed/E22_20260811053002_6f4784bc_overlay.png', 'processed/E22_20260811053002_6f4784bc_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 03:30:56'),
(66, 'A03_20260811054224_7789ec39', 'A03_20260811054224_7789ec39.jpg', 'Zone A', 'High', 10.90, 114288, 1048576, 98.49, 'processed/A03_20260811054224_7789ec39_overlay.png', 'processed/A03_20260811054224_7789ec39_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 03:43:22'),
(67, 'D15_20260811060701_7033cebf', 'D15_20260811060701_7033cebf.jpg', 'Zone D', 'High', 24.78, 259888, 1048576, 92.90, 'processed/D15_20260811060701_7033cebf_overlay.png', 'processed/D15_20260811060701_7033cebf_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 04:07:56'),
(68, 'C18_20260811062728_68956199', 'C18_20260811062728_68956199.jpg', 'Zone C', 'Low', 4.71, 49346, 1048576, 97.75, 'processed/C18_20260811062728_68956199_overlay.png', 'processed/C18_20260811062728_68956199_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 04:28:23'),
(69, 'A05_20260811064724_90dcd8b9', 'A05_20260811064724_90dcd8b9.jpg', 'Zone A', 'High', 9.49, 99539, 1048576, 98.75, 'processed/A05_20260811064724_90dcd8b9_overlay.png', 'processed/A05_20260811064724_90dcd8b9_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 04:48:21'),
(70, 'A05_20260811065533_789201d0', 'A05_20260811065533_789201d0.jpg', 'Zone A', 'High', 9.49, 99539, 1048576, 98.75, 'processed/A05_20260811065533_789201d0_overlay.png', 'processed/A05_20260811065533_789201d0_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 04:56:29'),
(71, 'A01_20260811070919_afe30b7d', 'A01_20260811070919_afe30b7d.jpg', 'Zone A', 'Low', 2.28, 23865, 1048576, 96.72, 'processed/A01_20260811070919_afe30b7d_overlay.png', 'processed/A01_20260811070919_afe30b7d_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 05:10:16'),
(72, 'A11_20260811071456_3c1d45fd', 'A11_20260811071456_3c1d45fd.jpg', 'Zone A', 'High', 6.52, 68414, 1048576, 98.50, 'processed/A11_20260811071456_3c1d45fd_overlay.png', 'processed/A11_20260811071456_3c1d45fd_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 05:15:55'),
(73, 'B02_20260811072511_99c7964e', 'B02_20260811072511_99c7964e.jpg', 'Zone B', 'High', 5.39, 56530, 1048576, 96.44, 'processed/B02_20260811072511_99c7964e_overlay.png', 'processed/B02_20260811072511_99c7964e_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 05:26:11'),
(74, 'C09_20260811073643_2a8033d2', 'C09_20260811073643_2a8033d2.jpg', 'Zone C', 'High', 14.68, 153935, 1048576, 96.98, 'processed/C09_20260811073643_2a8033d2_overlay.png', 'processed/C09_20260811073643_2a8033d2_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 05:37:43'),
(75, 'A26_20260811074600_242c12ab', 'A26_20260811074600_242c12ab.jpg', 'Zone A', 'Low', 1.34, 14017, 1048576, 93.49, 'processed/A26_20260811074600_242c12ab_overlay.png', 'processed/A26_20260811074600_242c12ab_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 05:46:58'),
(76, 'A10_20260811075917_5ab2db6f', 'A10_20260811075917_5ab2db6f.jpg', 'Zone A', 'High', 6.35, 66622, 1048576, 98.79, 'processed/A10_20260811075917_5ab2db6f_overlay.png', 'processed/A10_20260811075917_5ab2db6f_mask.png', 'DeepLabV3+', 'No', NULL, '2026-08-11 06:00:13');

-- --------------------------------------------------------

--
-- Table structure for table `images`
--

CREATE TABLE `images` (
  `id` int(11) NOT NULL,
  `image_id` varchar(120) NOT NULL,
  `image_file` varchar(255) NOT NULL,
  `original_image_path` varchar(255) NOT NULL,
  `processed_image_path` varchar(255) DEFAULT NULL,
  `mask_image_path` varchar(255) DEFAULT NULL,
  `zone_id` varchar(20) NOT NULL DEFAULT 'Unknown',
  `model_used` varchar(100) NOT NULL DEFAULT 'DeepLabV3+',
  `analysis_status` enum('Uploaded','Processing','Completed','Failed') DEFAULT 'Uploaded',
  `analysis_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `images`
--

INSERT INTO `images` (`id`, `image_id`, `image_file`, `original_image_path`, `processed_image_path`, `mask_image_path`, `zone_id`, `model_used`, `analysis_status`, `analysis_date`) VALUES
(26, 'B10_jpg_rf_976d25e0becb88db46ad124810f47d27_20260801064411_434ae4fd', 'B10_jpg_rf_976d25e0becb88db46ad124810f47d27_20260801064411_434ae4fd.jpg', 'uploads/B10_jpg_rf_976d25e0becb88db46ad124810f47d27_20260801064411_434ae4fd.jpg', 'processed/B10_jpg_rf_976d25e0becb88db46ad124810f47d27_20260801064411_434ae4fd_overlay.png', 'processed/B10_jpg_rf_976d25e0becb88db46ad124810f47d27_20260801064411_434ae4fd_mask.png', 'Zone B', 'DeepLabV3+', 'Completed', '2026-08-01 04:44:11'),
(27, 'B1_jpg_rf_d62d8709040841c8fdf0ed2e7c5a8fca_20260801064523_5e28fce6', 'B1_jpg_rf_d62d8709040841c8fdf0ed2e7c5a8fca_20260801064523_5e28fce6.jpg', 'uploads/B1_jpg_rf_d62d8709040841c8fdf0ed2e7c5a8fca_20260801064523_5e28fce6.jpg', 'processed/B1_jpg_rf_d62d8709040841c8fdf0ed2e7c5a8fca_20260801064523_5e28fce6_overlay.png', 'processed/B1_jpg_rf_d62d8709040841c8fdf0ed2e7c5a8fca_20260801064523_5e28fce6_mask.png', 'Zone B', 'DeepLabV3+', 'Completed', '2026-08-01 04:45:23'),
(28, 'A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801064608_1e7c5898', 'A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801064608_1e7c5898.jpg', 'uploads/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801064608_1e7c5898.jpg', 'processed/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801064608_1e7c5898_overlay.png', 'processed/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801064608_1e7c5898_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-01 04:46:08'),
(29, 'A57_jpg_rf_6fff0a55aa147482bd363d86088a9da5_20260801064930_842bb22a', 'A57_jpg_rf_6fff0a55aa147482bd363d86088a9da5_20260801064930_842bb22a.jpg', 'uploads/A57_jpg_rf_6fff0a55aa147482bd363d86088a9da5_20260801064930_842bb22a.jpg', 'processed/A57_jpg_rf_6fff0a55aa147482bd363d86088a9da5_20260801064930_842bb22a_overlay.png', 'processed/A57_jpg_rf_6fff0a55aa147482bd363d86088a9da5_20260801064930_842bb22a_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-01 04:49:30'),
(30, 'A46_jpg_rf_58caf0dfdc62ee16f0361786250ae37f_20260801065036_f1348176', 'A46_jpg_rf_58caf0dfdc62ee16f0361786250ae37f_20260801065036_f1348176.jpg', 'uploads/A46_jpg_rf_58caf0dfdc62ee16f0361786250ae37f_20260801065036_f1348176.jpg', 'processed/A46_jpg_rf_58caf0dfdc62ee16f0361786250ae37f_20260801065036_f1348176_overlay.png', 'processed/A46_jpg_rf_58caf0dfdc62ee16f0361786250ae37f_20260801065036_f1348176_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-01 04:50:36'),
(31, 'A34_jpg_rf_8b4acac219c88fc1ce8a2e9548d5e693_20260801065128_a42cde23', 'A34_jpg_rf_8b4acac219c88fc1ce8a2e9548d5e693_20260801065128_a42cde23.jpg', 'uploads/A34_jpg_rf_8b4acac219c88fc1ce8a2e9548d5e693_20260801065128_a42cde23.jpg', 'processed/A34_jpg_rf_8b4acac219c88fc1ce8a2e9548d5e693_20260801065128_a42cde23_overlay.png', 'processed/A34_jpg_rf_8b4acac219c88fc1ce8a2e9548d5e693_20260801065128_a42cde23_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-01 04:51:28'),
(32, 'A31_jpg_rf_f79bc9751de7f4d3e9029cfaab18f066_20260801065220_d284ec3d', 'A31_jpg_rf_f79bc9751de7f4d3e9029cfaab18f066_20260801065220_d284ec3d.jpg', 'uploads/A31_jpg_rf_f79bc9751de7f4d3e9029cfaab18f066_20260801065220_d284ec3d.jpg', 'processed/A31_jpg_rf_f79bc9751de7f4d3e9029cfaab18f066_20260801065220_d284ec3d_overlay.png', 'processed/A31_jpg_rf_f79bc9751de7f4d3e9029cfaab18f066_20260801065220_d284ec3d_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-01 04:52:20'),
(33, 'A28_jpg_rf_0dfda88ffc303533230e1a490acc31b1_20260801065306_4df401fd', 'A28_jpg_rf_0dfda88ffc303533230e1a490acc31b1_20260801065306_4df401fd.jpg', 'uploads/A28_jpg_rf_0dfda88ffc303533230e1a490acc31b1_20260801065306_4df401fd.jpg', 'processed/A28_jpg_rf_0dfda88ffc303533230e1a490acc31b1_20260801065306_4df401fd_overlay.png', 'processed/A28_jpg_rf_0dfda88ffc303533230e1a490acc31b1_20260801065306_4df401fd_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-01 04:53:06'),
(34, 'A7_jpg_rf_bbb14badff539ccc49bf964bed33bf8f_20260801065355_88a6a304', 'A7_jpg_rf_bbb14badff539ccc49bf964bed33bf8f_20260801065355_88a6a304.jpg', 'uploads/A7_jpg_rf_bbb14badff539ccc49bf964bed33bf8f_20260801065355_88a6a304.jpg', 'processed/A7_jpg_rf_bbb14badff539ccc49bf964bed33bf8f_20260801065355_88a6a304_overlay.png', 'processed/A7_jpg_rf_bbb14badff539ccc49bf964bed33bf8f_20260801065355_88a6a304_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-01 04:53:55'),
(35, 'A2_jpg_rf_f1a4e9123d8b53ad83ce6222330bf190_20260801065702_71b61f80', 'A2_jpg_rf_f1a4e9123d8b53ad83ce6222330bf190_20260801065702_71b61f80.jpg', 'uploads/A2_jpg_rf_f1a4e9123d8b53ad83ce6222330bf190_20260801065702_71b61f80.jpg', 'processed/A2_jpg_rf_f1a4e9123d8b53ad83ce6222330bf190_20260801065702_71b61f80_overlay.png', 'processed/A2_jpg_rf_f1a4e9123d8b53ad83ce6222330bf190_20260801065702_71b61f80_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-01 04:57:02'),
(36, 'A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801070324_b77e06ec', 'A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801070324_b77e06ec.jpg', 'uploads/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801070324_b77e06ec.jpg', 'processed/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801070324_b77e06ec_overlay.png', 'processed/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801070324_b77e06ec_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-01 05:03:24'),
(37, 'A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801070750_9633919b', 'A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801070750_9633919b.jpg', 'uploads/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801070750_9633919b.jpg', 'processed/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801070750_9633919b_overlay.png', 'processed/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801070750_9633919b_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-01 05:07:50'),
(38, 'A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801071146_34167f4a', 'A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801071146_34167f4a.jpg', 'uploads/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801071146_34167f4a.jpg', 'processed/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801071146_34167f4a_overlay.png', 'processed/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801071146_34167f4a_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-01 05:11:46'),
(39, 'A57_jpg_rf_6fff0a55aa147482bd363d86088a9da5_20260801071958_facd6080', 'A57_jpg_rf_6fff0a55aa147482bd363d86088a9da5_20260801071958_facd6080.jpg', 'uploads/A57_jpg_rf_6fff0a55aa147482bd363d86088a9da5_20260801071958_facd6080.jpg', 'processed/A57_jpg_rf_6fff0a55aa147482bd363d86088a9da5_20260801071958_facd6080_overlay.png', 'processed/A57_jpg_rf_6fff0a55aa147482bd363d86088a9da5_20260801071958_facd6080_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-01 05:19:58'),
(40, 'A28_jpg_rf_0dfda88ffc303533230e1a490acc31b1_20260801072050_e09ace74', 'A28_jpg_rf_0dfda88ffc303533230e1a490acc31b1_20260801072050_e09ace74.jpg', 'uploads/A28_jpg_rf_0dfda88ffc303533230e1a490acc31b1_20260801072050_e09ace74.jpg', 'processed/A28_jpg_rf_0dfda88ffc303533230e1a490acc31b1_20260801072050_e09ace74_overlay.png', 'processed/A28_jpg_rf_0dfda88ffc303533230e1a490acc31b1_20260801072050_e09ace74_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-01 05:20:50'),
(41, 'A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801072152_1e522d4d', 'A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801072152_1e522d4d.jpg', 'uploads/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801072152_1e522d4d.jpg', 'processed/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801072152_1e522d4d_overlay.png', 'processed/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801072152_1e522d4d_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-01 05:21:52'),
(42, 'A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801072342_7a0ff763', 'A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801072342_7a0ff763.jpg', 'uploads/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801072342_7a0ff763.jpg', 'processed/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801072342_7a0ff763_overlay.png', 'processed/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801072342_7a0ff763_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-01 05:23:42'),
(43, 'E23_jpg_rf_d2eee67d11e63edeb51caf2643cabada_20260801073211_9f1bdbc1', 'E23_jpg_rf_d2eee67d11e63edeb51caf2643cabada_20260801073211_9f1bdbc1.jpg', 'uploads/E23_jpg_rf_d2eee67d11e63edeb51caf2643cabada_20260801073211_9f1bdbc1.jpg', 'processed/E23_jpg_rf_d2eee67d11e63edeb51caf2643cabada_20260801073211_9f1bdbc1_overlay.png', 'processed/E23_jpg_rf_d2eee67d11e63edeb51caf2643cabada_20260801073211_9f1bdbc1_mask.png', 'Zone E', 'DeepLabV3+', 'Completed', '2026-08-01 05:32:11'),
(44, 'E22_jpg_rf_34a755feccb72fe43f9ab78b86a79c50_20260801073306_0dc4241f', 'E22_jpg_rf_34a755feccb72fe43f9ab78b86a79c50_20260801073306_0dc4241f.jpg', 'uploads/E22_jpg_rf_34a755feccb72fe43f9ab78b86a79c50_20260801073306_0dc4241f.jpg', 'processed/E22_jpg_rf_34a755feccb72fe43f9ab78b86a79c50_20260801073306_0dc4241f_overlay.png', 'processed/E22_jpg_rf_34a755feccb72fe43f9ab78b86a79c50_20260801073306_0dc4241f_mask.png', 'Zone E', 'DeepLabV3+', 'Completed', '2026-08-01 05:33:06'),
(45, 'D47_jpg_rf_00bf65e16349a6818b4be171859ec720_20260801073351_d3117511', 'D47_jpg_rf_00bf65e16349a6818b4be171859ec720_20260801073351_d3117511.jpg', 'uploads/D47_jpg_rf_00bf65e16349a6818b4be171859ec720_20260801073351_d3117511.jpg', 'processed/D47_jpg_rf_00bf65e16349a6818b4be171859ec720_20260801073351_d3117511_overlay.png', 'processed/D47_jpg_rf_00bf65e16349a6818b4be171859ec720_20260801073351_d3117511_mask.png', 'Zone D', 'DeepLabV3+', 'Completed', '2026-08-01 05:33:51'),
(46, 'C54_jpg_rf_01f4cd81c7190f2b5097fbbd8ac6d458_20260801073444_99efa20d', 'C54_jpg_rf_01f4cd81c7190f2b5097fbbd8ac6d458_20260801073444_99efa20d.jpg', 'uploads/C54_jpg_rf_01f4cd81c7190f2b5097fbbd8ac6d458_20260801073444_99efa20d.jpg', 'processed/C54_jpg_rf_01f4cd81c7190f2b5097fbbd8ac6d458_20260801073444_99efa20d_overlay.png', 'processed/C54_jpg_rf_01f4cd81c7190f2b5097fbbd8ac6d458_20260801073444_99efa20d_mask.png', 'Zone C', 'DeepLabV3+', 'Completed', '2026-08-01 05:34:44'),
(47, 'B1_jpg_rf_d62d8709040841c8fdf0ed2e7c5a8fca_20260801073539_4a2796f3', 'B1_jpg_rf_d62d8709040841c8fdf0ed2e7c5a8fca_20260801073539_4a2796f3.jpg', 'uploads/B1_jpg_rf_d62d8709040841c8fdf0ed2e7c5a8fca_20260801073539_4a2796f3.jpg', 'processed/B1_jpg_rf_d62d8709040841c8fdf0ed2e7c5a8fca_20260801073539_4a2796f3_overlay.png', 'processed/B1_jpg_rf_d62d8709040841c8fdf0ed2e7c5a8fca_20260801073539_4a2796f3_mask.png', 'Zone B', 'DeepLabV3+', 'Completed', '2026-08-01 05:35:39'),
(48, 'A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801073828_2cca193d', 'A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801073828_2cca193d.jpg', 'uploads/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801073828_2cca193d.jpg', 'processed/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801073828_2cca193d_overlay.png', 'processed/A68_jpg_rf_d292badb63e038bdfed3fc8b09ca0c56_20260801073828_2cca193d_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-01 05:38:28'),
(54, 'A34_jpg_rf_8b4acac219c88fc1ce8a2e9548d5e693_20260811015617_5523ea5b', 'A34_jpg_rf_8b4acac219c88fc1ce8a2e9548d5e693_20260811015617_5523ea5b.jpg', 'uploads/A34_jpg_rf_8b4acac219c88fc1ce8a2e9548d5e693_20260811015617_5523ea5b.jpg', 'processed/A34_jpg_rf_8b4acac219c88fc1ce8a2e9548d5e693_20260811015617_5523ea5b_overlay.png', 'processed/A34_jpg_rf_8b4acac219c88fc1ce8a2e9548d5e693_20260811015617_5523ea5b_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-10 23:56:17'),
(55, 'E26_20260811035216_77c90db0', 'E26_20260811035216_77c90db0.jpg', 'uploads/E26_20260811035216_77c90db0.jpg', 'processed/E26_20260811035216_77c90db0_overlay.png', 'processed/E26_20260811035216_77c90db0_mask.png', 'Zone E', 'DeepLabV3+', 'Completed', '2026-08-11 01:52:16'),
(56, 'A10_20260811040717_28d38719', 'A10_20260811040717_28d38719.jpg', 'uploads/A10_20260811040717_28d38719.jpg', 'processed/A10_20260811040717_28d38719_overlay.png', 'processed/A10_20260811040717_28d38719_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-11 02:07:17'),
(57, 'A03_20260811041616_a09d1426', 'A03_20260811041616_a09d1426.jpg', 'uploads/A03_20260811041616_a09d1426.jpg', 'processed/A03_20260811041616_a09d1426_overlay.png', 'processed/A03_20260811041616_a09d1426_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-11 02:16:16'),
(58, 'A13_20260811042029_c190ef19', 'A13_20260811042029_c190ef19.jpg', 'uploads/A13_20260811042029_c190ef19.jpg', 'processed/A13_20260811042029_c190ef19_overlay.png', 'processed/A13_20260811042029_c190ef19_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-11 02:20:29'),
(59, 'A22_20260811043104_bdf3bd45', 'A22_20260811043104_bdf3bd45.jpg', 'uploads/A22_20260811043104_bdf3bd45.jpg', 'processed/A22_20260811043104_bdf3bd45_overlay.png', 'processed/A22_20260811043104_bdf3bd45_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-11 02:31:04'),
(60, 'B09_20260811044940_8db198b5', 'B09_20260811044940_8db198b5.jpg', 'uploads/B09_20260811044940_8db198b5.jpg', 'processed/B09_20260811044940_8db198b5_overlay.png', 'processed/B09_20260811044940_8db198b5_mask.png', 'Zone B', 'DeepLabV3+', 'Completed', '2026-08-11 02:49:41'),
(61, 'A05_20260811045836_3b0be20a', 'A05_20260811045836_3b0be20a.jpg', 'uploads/A05_20260811045836_3b0be20a.jpg', 'processed/A05_20260811045836_3b0be20a_overlay.png', 'processed/A05_20260811045836_3b0be20a_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-11 02:58:36'),
(62, 'A20_20260811050307_084c55ad', 'A20_20260811050307_084c55ad.jpg', 'uploads/A20_20260811050307_084c55ad.jpg', 'processed/A20_20260811050307_084c55ad_overlay.png', 'processed/A20_20260811050307_084c55ad_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-11 03:03:07'),
(63, 'B20_20260811051453_530d7644', 'B20_20260811051453_530d7644.jpg', 'uploads/B20_20260811051453_530d7644.jpg', 'processed/B20_20260811051453_530d7644_overlay.png', 'processed/B20_20260811051453_530d7644_mask.png', 'Zone B', 'DeepLabV3+', 'Completed', '2026-08-11 03:14:53'),
(64, 'A05_20260811051838_2442bd79', 'A05_20260811051838_2442bd79.jpg', 'uploads/A05_20260811051838_2442bd79.jpg', 'processed/A05_20260811051838_2442bd79_overlay.png', 'processed/A05_20260811051838_2442bd79_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-11 03:18:38'),
(66, 'E22_20260811053002_6f4784bc', 'E22_20260811053002_6f4784bc.jpg', 'uploads/E22_20260811053002_6f4784bc.jpg', 'processed/E22_20260811053002_6f4784bc_overlay.png', 'processed/E22_20260811053002_6f4784bc_mask.png', 'Zone E', 'DeepLabV3+', 'Completed', '2026-08-11 03:30:02'),
(67, 'A03_20260811054224_7789ec39', 'A03_20260811054224_7789ec39.jpg', 'uploads/A03_20260811054224_7789ec39.jpg', 'processed/A03_20260811054224_7789ec39_overlay.png', 'processed/A03_20260811054224_7789ec39_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-11 03:42:24'),
(68, 'D15_20260811060701_7033cebf', 'D15_20260811060701_7033cebf.jpg', 'uploads/D15_20260811060701_7033cebf.jpg', 'processed/D15_20260811060701_7033cebf_overlay.png', 'processed/D15_20260811060701_7033cebf_mask.png', 'Zone D', 'DeepLabV3+', 'Completed', '2026-08-11 04:07:01'),
(69, 'C18_20260811062728_68956199', 'C18_20260811062728_68956199.jpg', 'uploads/C18_20260811062728_68956199.jpg', 'processed/C18_20260811062728_68956199_overlay.png', 'processed/C18_20260811062728_68956199_mask.png', 'Zone C', 'DeepLabV3+', 'Completed', '2026-08-11 04:27:28'),
(70, 'A05_20260811064724_90dcd8b9', 'A05_20260811064724_90dcd8b9.jpg', 'uploads/A05_20260811064724_90dcd8b9.jpg', 'processed/A05_20260811064724_90dcd8b9_overlay.png', 'processed/A05_20260811064724_90dcd8b9_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-11 04:47:24'),
(71, 'A05_20260811065533_789201d0', 'A05_20260811065533_789201d0.jpg', 'uploads/A05_20260811065533_789201d0.jpg', 'processed/A05_20260811065533_789201d0_overlay.png', 'processed/A05_20260811065533_789201d0_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-11 04:55:33'),
(72, 'A01_20260811070919_afe30b7d', 'A01_20260811070919_afe30b7d.jpg', 'uploads/A01_20260811070919_afe30b7d.jpg', 'processed/A01_20260811070919_afe30b7d_overlay.png', 'processed/A01_20260811070919_afe30b7d_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-11 05:09:19'),
(73, 'A11_20260811071456_3c1d45fd', 'A11_20260811071456_3c1d45fd.jpg', 'uploads/A11_20260811071456_3c1d45fd.jpg', 'processed/A11_20260811071456_3c1d45fd_overlay.png', 'processed/A11_20260811071456_3c1d45fd_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-11 05:14:56'),
(74, 'B02_20260811072511_99c7964e', 'B02_20260811072511_99c7964e.jpg', 'uploads/B02_20260811072511_99c7964e.jpg', 'processed/B02_20260811072511_99c7964e_overlay.png', 'processed/B02_20260811072511_99c7964e_mask.png', 'Zone B', 'DeepLabV3+', 'Completed', '2026-08-11 05:25:11'),
(75, 'C09_20260811073643_2a8033d2', 'C09_20260811073643_2a8033d2.jpg', 'uploads/C09_20260811073643_2a8033d2.jpg', 'processed/C09_20260811073643_2a8033d2_overlay.png', 'processed/C09_20260811073643_2a8033d2_mask.png', 'Zone C', 'DeepLabV3+', 'Completed', '2026-08-11 05:36:43'),
(76, 'A26_20260811074600_242c12ab', 'A26_20260811074600_242c12ab.jpg', 'uploads/A26_20260811074600_242c12ab.jpg', 'processed/A26_20260811074600_242c12ab_overlay.png', 'processed/A26_20260811074600_242c12ab_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-11 05:46:00'),
(77, 'A10_20260811075917_5ab2db6f', 'A10_20260811075917_5ab2db6f.jpg', 'uploads/A10_20260811075917_5ab2db6f.jpg', 'processed/A10_20260811075917_5ab2db6f_overlay.png', 'processed/A10_20260811075917_5ab2db6f_mask.png', 'Zone A', 'DeepLabV3+', 'Completed', '2026-08-11 05:59:17');

-- --------------------------------------------------------

--
-- Table structure for table `treatment_plans`
--

CREATE TABLE `treatment_plans` (
  `id` int(11) NOT NULL,
  `zone_id` varchar(20) NOT NULL,
  `analysed_trees` int(11) NOT NULL DEFAULT 0,
  `treatment_trees` int(11) NOT NULL DEFAULT 0,
  `infested_trees` int(11) NOT NULL DEFAULT 0,
  `average_coverage` decimal(7,2) NOT NULL DEFAULT 0.00,
  `maximum_coverage` decimal(7,2) NOT NULL DEFAULT 0.00,
  `average_confidence` decimal(7,2) NOT NULL DEFAULT 0.00,
  `equivalent_area_ha` decimal(12,6) NOT NULL DEFAULT 0.000000,
  `recommended_herbicide` varchar(100) NOT NULL DEFAULT 'Glyphosate',
  `application_rate` varchar(50) NOT NULL DEFAULT '400 g a.i./ha',
  `manager_confirmed` enum('Yes','No') NOT NULL DEFAULT 'No',
  `herbicide_cost_per_hectare` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `herbicide_cost_per_tree` decimal(12,8) NOT NULL DEFAULT 0.00000000,
  `herbicide_grams_per_hectare` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `herbicide_grams_per_tree` decimal(12,8) NOT NULL DEFAULT 0.00000000,
  `water_litres_per_tree` decimal(12,8) NOT NULL DEFAULT 0.00000000,
  `water_cost_per_tree` decimal(12,8) NOT NULL DEFAULT 0.00000000,
  `uav_hours_per_tree` decimal(12,10) NOT NULL DEFAULT 0.0000000000,
  `uav_labour_cost_per_tree` decimal(12,8) NOT NULL DEFAULT 0.00000000,
  `herbicide_required_grams` decimal(14,6) NOT NULL DEFAULT 0.000000,
  `water_required_litres` decimal(14,6) NOT NULL DEFAULT 0.000000,
  `uav_labour_hours` decimal(14,8) NOT NULL DEFAULT 0.00000000,
  `herbicide_cost` decimal(14,6) NOT NULL DEFAULT 0.000000,
  `water_cost` decimal(14,6) NOT NULL DEFAULT 0.000000,
  `uav_labour_cost` decimal(14,6) NOT NULL DEFAULT 0.000000,
  `total_cost` decimal(14,6) NOT NULL DEFAULT 0.000000,
  `priority_level` enum('Low','High') NOT NULL DEFAULT 'Low',
  `recommended_action` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `treatment_plans`
--

INSERT INTO `treatment_plans` (`id`, `zone_id`, `analysed_trees`, `treatment_trees`, `infested_trees`, `average_coverage`, `maximum_coverage`, `average_confidence`, `equivalent_area_ha`, `recommended_herbicide`, `application_rate`, `manager_confirmed`, `herbicide_cost_per_hectare`, `herbicide_cost_per_tree`, `herbicide_grams_per_hectare`, `herbicide_grams_per_tree`, `water_litres_per_tree`, `water_cost_per_tree`, `uav_hours_per_tree`, `uav_labour_cost_per_tree`, `herbicide_required_grams`, `water_required_litres`, `uav_labour_hours`, `herbicide_cost`, `water_cost`, `uav_labour_cost`, `total_cost`, `priority_level`, `recommended_action`, `created_at`, `updated_at`) VALUES
(1, 'Zone A', 16, 11, 4, 7.73, 18.43, 96.98, 0.100917, 'Glyphosate', '1600 g a.i./ha', 'No', 76.1000, 0.69816514, 1600.0000, 14.67889908, 0.36697248, 0.00128807, 0.0048929664, 0.07642202, 161.467890, 4.036697, 0.05382263, 7.679817, 0.014169, 0.840642, 8.534628, 'High', 'Zone A has 11 tree(s) requiring treatment (68.8% of analysed trees). Use the selected herbicide plan and verify field conditions before UAV treatment.', '2026-08-07 11:13:44', '2026-08-11 06:04:11'),
(2, 'Zone B', 4, 3, 0, 6.24, 9.43, 96.65, 0.027523, 'Glyphosate', '400 g a.i./ha', 'No', 30.3000, 0.27798165, 400.0000, 3.66972477, 0.36697248, 0.00128807, 0.0048929664, 0.07642202, 11.009174, 1.100917, 0.01467890, 0.833945, 0.003864, 0.229266, 1.067075, 'High', 'Zone B has 3 tree(s) requiring treatment (75.0% of analysed trees). Use the selected herbicide plan and verify field conditions before UAV treatment.', '2026-08-07 11:13:44', '2026-08-11 06:04:11'),
(3, 'Zone C', 3, 2, 1, 9.48, 14.68, 97.72, 0.018349, 'Glyphosate', '400 g a.i./ha', 'No', 30.3000, 0.27798165, 400.0000, 3.66972477, 0.36697248, 0.00128807, 0.0048929664, 0.07642202, 7.339450, 0.733945, 0.00978593, 0.555963, 0.002576, 0.152844, 0.711383, 'High', 'Zone C has 2 tree(s) requiring treatment (66.7% of analysed trees). Use the selected herbicide plan and verify field conditions before UAV treatment.', '2026-08-07 11:13:44', '2026-08-11 06:04:11'),
(4, 'Zone D', 2, 1, 1, 13.62, 24.78, 94.44, 0.009174, 'Glyphosate', '400 g a.i./ha', 'No', 30.3000, 0.27798165, 400.0000, 3.66972477, 0.36697248, 0.00128807, 0.0048929664, 0.07642202, 3.669725, 0.366972, 0.00489297, 0.277982, 0.001288, 0.076422, 0.355692, 'High', 'Zone D has 1 tree(s) requiring treatment (50.0% of analysed trees). Use the selected herbicide plan and verify field conditions before UAV treatment.', '2026-08-07 11:13:44', '2026-08-11 06:04:11'),
(5, 'Zone E', 4, 3, 2, 8.52, 11.98, 97.42, 0.027523, 'Glyphosate', '400 g a.i./ha', 'No', 30.3000, 0.27798165, 400.0000, 3.66972477, 0.36697248, 0.00128807, 0.0048929664, 0.07642202, 11.009174, 1.100917, 0.01467890, 0.833945, 0.003864, 0.229266, 1.067075, 'High', 'Zone E has 3 tree(s) requiring treatment (75.0% of analysed trees). Use the selected herbicide plan and verify field conditions before UAV treatment.', '2026-08-07 11:13:44', '2026-08-11 06:04:11');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(50) DEFAULT 'Manager',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `email`, `password`, `role`, `created_at`) VALUES
(1, 'Administrator', 'admin@nfw.com', 'admin123', 'Admin', '2026-07-08 15:47:29'),
(2, 'Ili Rusley', 'ili@nfw.com', '1234', 'Manager', '2026-07-08 17:02:34'),
(3, 'Nurul Fatihana', 'hana@nfw.com', '2406', 'Manager', '2026-07-09 11:00:13'),
(4, 'Joshua Wong', 'joshua@iw.com', '123', 'Manager', '2026-07-10 09:03:36');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `contact_messages`
--
ALTER TABLE `contact_messages`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `detection_results`
--
ALTER TABLE `detection_results`
  ADD PRIMARY KEY (`id`),
  ADD KEY `image_id` (`image_id`);

--
-- Indexes for table `images`
--
ALTER TABLE `images`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `image_id` (`image_id`);

--
-- Indexes for table `treatment_plans`
--
ALTER TABLE `treatment_plans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `zone_id` (`zone_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `contact_messages`
--
ALTER TABLE `contact_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `detection_results`
--
ALTER TABLE `detection_results`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=77;

--
-- AUTO_INCREMENT for table `images`
--
ALTER TABLE `images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=78;

--
-- AUTO_INCREMENT for table `treatment_plans`
--
ALTER TABLE `treatment_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2389;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `detection_results`
--
ALTER TABLE `detection_results`
  ADD CONSTRAINT `detection_results_ibfk_1` FOREIGN KEY (`image_id`) REFERENCES `images` (`image_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
