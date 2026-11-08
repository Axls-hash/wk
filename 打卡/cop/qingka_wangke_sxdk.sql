/*
Navicat MySQL Data Transfer

Source Server
Source Server Version : 50650
Source Host           : 127.0.0.1:3306
Source Database

Target Server Type    : MYSQL
Target Server Version : 50650
File Encoding         : 65001

Date: 2023-09-14 17:33:54
*/

SET FOREIGN_KEY_CHECKS=0;

-- ----------------------------
-- Table structure for qingka_wangke_sxdk
-- ----------------------------
DROP TABLE IF EXISTS `qingka_wangke_sxdk`;
CREATE TABLE `qingka_wangke_sxdk` (
  `id` int(9) NOT NULL AUTO_INCREMENT,
  `sxdkId` int(9) NOT NULL,
  `uid` int(9) NOT NULL,
  `platform` varchar(10) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `password` varchar(50) NOT NULL,
  `code` int(2) NOT NULL,
  `wxpush` varchar(255) DEFAULT NULL,
  `name` varchar(10) DEFAULT NULL,
  `address` varchar(255) NOT NULL,
  `up_check_time` varchar(50) NOT NULL,
  `down_check_time` varchar(50) DEFAULT NULL,
  `check_week` varchar(50) NOT NULL,
  `end_time` varchar(50) NOT NULL,
  `day_paper` int(2) NOT NULL,
  `week_paper` int(2) NOT NULL,
  `month_paper` int(2) NOT NULL,
  `createTime` varchar(50) NOT NULL,
  `updateTime` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4;
