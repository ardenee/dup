-- phpMyAdmin SQL Dump
-- version 3.5.3
-- http://www.phpmyadmin.net
--
-- Host: localhost
-- Generation Time: Sep 09, 2013 at 03:16 PM
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
-- Table structure for table `exts`
--

CREATE TABLE IF NOT EXISTS `exts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exts` varchar(255) NOT NULL,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=11 ;

-- --------------------------------------------------------

--
-- Table structure for table `files`
--

CREATE TABLE IF NOT EXISTS `files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `file` varchar(255) NOT NULL,
  `size` double NOT NULL,
  `fdate` varchar(20) NOT NULL,
  `crc32` varchar(8) NOT NULL,
  `profile` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `file_2` (`file`,`profile`),
  KEY `crc32` (`crc32`),
  KEY `size` (`size`),
  KEY `file` (`file`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `logs`
--

CREATE TABLE IF NOT EXISTS `logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `str` text NOT NULL,
  `type` int(11) NOT NULL,
  `page` varchar(255) NOT NULL,
  `function` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=13 ;

-- --------------------------------------------------------

--
-- Table structure for table `profiles`
--

CREATE TABLE IF NOT EXISTS `profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `msize` int(11) NOT NULL,
  `path` varchar(255) NOT NULL,
  `mpath` varchar(255) NOT NULL,
  `crc32` int(11) NOT NULL DEFAULT '0',
  `filetypes` text NOT NULL,
  `scan` tinyint(1) NOT NULL,
  `lastrun` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `type` int(11) NOT NULL DEFAULT '1',
  `files` int(11) NOT NULL DEFAULT '0',
  `dups` int(11) NOT NULL DEFAULT '0',
  `runs` int(11) NOT NULL DEFAULT '0',
  `skipedfilestypes` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `renametype` int(11) NOT NULL DEFAULT '0',
  `namecleanup` int(11) NOT NULL DEFAULT '0',
  `cleanupfilter` text NOT NULL,
  `dot` int(11) NOT NULL DEFAULT '0',
  `under` int(11) NOT NULL DEFAULT '0',
  `doubleb` int(11) NOT NULL DEFAULT '0',
  `flatfolders` int(11) NOT NULL DEFAULT '0',
  `logs` int(11) NOT NULL DEFAULT '0',
  `displaymoved` int(11) NOT NULL DEFAULT '0',
  `cpfolder` int(11) NOT NULL DEFAULT '0',
  `shortfilenames` int(11) NOT NULL DEFAULT '0',
  `runtime` int(11) NOT NULL DEFAULT '3600',
  `autoupdate` int(11) NOT NULL DEFAULT '0',
  `ifolders` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=2 ;

-- --------------------------------------------------------

--
-- Table structure for table `status`
--

CREATE TABLE IF NOT EXISTS `status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `value` text NOT NULL,
  `data` text NOT NULL,
  `page` varchar(255) NOT NULL,
  `profileid` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `profileid` (`profileid`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `viewpages`
--

CREATE TABLE IF NOT EXISTS `viewpages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `data` text NOT NULL,
  `profile` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `profile` (`profile`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
