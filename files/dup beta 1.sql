-- phpMyAdmin SQL Dump
-- version 3.5.3
-- http://www.phpmyadmin.net
--
-- Host: localhost
-- Generation Time: Jan 22, 2014 at 01:24 PM
-- Server version: 5.1.49
-- PHP Version: 5.3.27

SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;

--
-- Database: `dup`
--

-- --------------------------------------------------------

--
-- Table structure for table `accounts`
--

DROP TABLE IF EXISTS `accounts`;
CREATE TABLE IF NOT EXISTS `accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `level` int(11) NOT NULL DEFAULT '0',
  `password` varchar(50) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=1 ;

INSERT INTO `accounts` (`id`, `name`, `level`, `password`) VALUES (1, 'admin', 1, 'afb7127bdf13d61f0cfd4819a0faf804');
-- admin - admin

-- --------------------------------------------------------

--
-- Table structure for table `files`
--

DROP TABLE IF EXISTS `files`;
CREATE TABLE IF NOT EXISTS `files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `size` double NOT NULL,
  `fdate` int(11) NOT NULL,
  `crc32` varchar(8) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL DEFAULT 'unknown',
  `profile` int(11) NOT NULL,
  `link` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `file_2` (`file`,`profile`),
  KEY `crc32` (`crc32`),
  KEY `size` (`size`),
  KEY `file` (`file`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `files_delete`
--

DROP TABLE IF EXISTS `files_delete`;
CREATE TABLE IF NOT EXISTS `files_delete` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `new` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `profile` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `file` (`file`,`profile`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=14 ;

-- --------------------------------------------------------

--
-- Table structure for table `files_link`
--

DROP TABLE IF EXISTS `files_link`;
CREATE TABLE IF NOT EXISTS `files_link` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `new` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `profile` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `new` (`new`,`profile`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `files_move`
--

DROP TABLE IF EXISTS `files_move`;
CREATE TABLE IF NOT EXISTS `files_move` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `new` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `profile` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `file` (`file`,`profile`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `files_rename`
--

DROP TABLE IF EXISTS `files_rename`;
CREATE TABLE IF NOT EXISTS `files_rename` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `new` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `profile` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `file` (`file`,`profile`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `logs`
--

DROP TABLE IF EXISTS `logs`;
CREATE TABLE IF NOT EXISTS `logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `str` text CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `type` int(11) NOT NULL,
  `page` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `line` int(11) NOT NULL,
  `function` text CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `msg`
--

DROP TABLE IF EXISTS `msg`;
CREATE TABLE IF NOT EXISTS `msg` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `message` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=1 ;

INSERT INTO `msg` (`id`, `message`) VALUES (1, '');

-- --------------------------------------------------------

--
-- Table structure for table `profiles`
--

DROP TABLE IF EXISTS `profiles`;
CREATE TABLE IF NOT EXISTS `profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `msize` int(11) NOT NULL,
  `path` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `mpath` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `crc32` int(15) NOT NULL DEFAULT '0',
  `filetypes` text CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `scan` tinyint(1) NOT NULL,
  `lastrun` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `type` int(11) NOT NULL DEFAULT '1',
  `files` int(11) NOT NULL DEFAULT '0',
  `dups` int(11) NOT NULL DEFAULT '0',
  `runs` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `profiletypes`
--

DROP TABLE IF EXISTS `profiletypes`;
CREATE TABLE IF NOT EXISTS `profiletypes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exts` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `name` varchar(50) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=1 ;

INSERT INTO `profiletypes` (`id`, `exts`, `name`) VALUES
(1, 'mobi,prc', 'Books'),
(2, 'cbr,cbz', 'Comics'),
(3, 'pdf,rtf,doc,docx', 'Documents'),
(4, 'avi,mp4,mkv,divx,mpg,rmvb,wmv,rm,ram,vob,wmv', 'Films'),
(5, 'iso,zip,rar,exe,msi,nds', 'Games'),
(6, 'wma,mpc,mp3,flac,wav,ogg', 'Music'),
(7, 'bmp,png,gif,jpg,jpeg', 'Pictures'),
(8, 'exe,msi,zip,rar', 'Programs'),
(9, 'avi,mp4,mkv,divx,mpg,rmvb,wmv,rm,ram,vob,wmv', 'TV Shows'),
(10, 'avi,mp4,mkv,divx,mpg,rmvb,wmv,rm,ram,vob,wmv', 'Videos'),
(11, '', 'All File Types');

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `renametype` int(11) NOT NULL DEFAULT '0',
  `namecleanup` int(11) NOT NULL DEFAULT '0',
  `cleanupfilter` text CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `dot` int(11) NOT NULL DEFAULT '0',
  `under` int(11) NOT NULL DEFAULT '0',
  `doubleb` int(11) NOT NULL DEFAULT '0',
  `flatfolders` int(11) NOT NULL DEFAULT '0',
  `logs` int(11) NOT NULL DEFAULT '0',
  `displaymoved` int(11) NOT NULL DEFAULT '0',
  `cpfolder` int(11) NOT NULL DEFAULT '0',
  `shortfilenames` int(11) NOT NULL DEFAULT '0',
  `runtime` int(11) NOT NULL DEFAULT '3600',
  `version` varchar(15) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `ifolders` text CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `download` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=1 ;

INSERT INTO `settings` (`id`, `renametype`, `namecleanup`, `cleanupfilter`, `dot`, `under`, `doubleb`, `flatfolders`, `logs`, `displaymoved`, `cpfolder`, `shortfilenames`, `runtime`, `version`, `ifolders`, `download`) VALUES
(1, 0, 1, 'DVDRip,BRRip,720p,1080p,1080i,480p,DVD', 1, 1, 1, 0, 0, 0, 1, 0, 10800, '0.7.0', '@eaDir,#recycle', 0);

-- --------------------------------------------------------

--
-- Table structure for table `status`
--

DROP TABLE IF EXISTS `status`;
CREATE TABLE IF NOT EXISTS `status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `value` text CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `data` text CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `profile` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `profile` (`profile`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `viewpages`
--

DROP TABLE IF EXISTS `viewpages`;
CREATE TABLE IF NOT EXISTS `viewpages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `data` text CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `profile` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `profile` (`profile`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 COLLATE=utf8_bin AUTO_INCREMENT=1 ;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
