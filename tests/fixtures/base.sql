SET NAMES utf8mb4;
START TRANSACTION;
CREATE TABLE `wp_options` (`option_id` bigint unsigned NOT NULL,`option_name` varchar(191),`option_value` longtext,`autoload` varchar(20),PRIMARY KEY (`option_id`)) DEFAULT CHARSET=utf8mb4;
INSERT INTO `wp_options` VALUES (1,'siteurl','https://base.test','yes'),(2,'home','https://base.test','yes');
CREATE TABLE `wp_posts` (
  `ID` bigint unsigned NOT NULL,
  `post_author` bigint unsigned NOT NULL DEFAULT 0,
  `post_date` datetime NOT NULL,
  `post_date_gmt` datetime NOT NULL,
  `post_content` longtext NOT NULL,
  `post_title` text NOT NULL,
  `post_excerpt` text NOT NULL,
  `post_status` varchar(20) NOT NULL,
  `comment_status` varchar(20) NOT NULL,
  `ping_status` varchar(20) NOT NULL,
  `post_password` varchar(255) NOT NULL,
  `post_name` varchar(200) NOT NULL,
  `to_ping` text NOT NULL,
  `pinged` text NOT NULL,
  `post_modified` datetime NOT NULL,
  `post_modified_gmt` datetime NOT NULL,
  `post_content_filtered` longtext NOT NULL,
  `post_parent` bigint unsigned NOT NULL DEFAULT 0,
  `guid` varchar(255) NOT NULL,
  `menu_order` int NOT NULL DEFAULT 0,
  `post_type` varchar(20) NOT NULL,
  `post_mime_type` varchar(100) NOT NULL,
  `comment_count` bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (`ID`)
) DEFAULT CHARSET=utf8mb4;
INSERT INTO `wp_posts` VALUES
(1,1,'2026-01-01 00:00:00','2026-01-01 00:00:00','Old body','Hello','old excerpt','publish','open','open','','hello','','','2026-01-02 00:00:00','2026-01-02 00:00:00','',0,'https://base.test/?p=1',0,'post','',0),
(2,1,'2026-01-03 00:00:00','2026-01-03 00:00:00','Base only','Base only','','publish','open','open','','base-only','','','2026-01-03 00:00:00','2026-01-03 00:00:00','',0,'https://base.test/?p=2',0,'page','',0),
(200,1,'2025-01-01 00:00:00','2025-01-01 00:00:00','a:1:{s:4:"type";s:7:"gallery";}','Gallery','gallery','publish','closed','closed','','field_gallery','','','2025-01-01 00:00:00','2025-01-01 00:00:00','',0,'',0,'acf-field','',0);
CREATE TABLE `wp_postmeta` (`meta_id` bigint unsigned NOT NULL,`post_id` bigint unsigned NOT NULL,`meta_key` varchar(255),`meta_value` longtext,PRIMARY KEY (`meta_id`)) DEFAULT CHARSET=utf8mb4;
INSERT INTO `wp_postmeta` VALUES (1,1,'color','blue');
CREATE TABLE `wp_terms` (`term_id` bigint unsigned NOT NULL,`name` varchar(200),`slug` varchar(200),`term_group` bigint NOT NULL DEFAULT 0,PRIMARY KEY (`term_id`)) DEFAULT CHARSET=utf8mb4;
INSERT INTO `wp_terms` VALUES (1,'News','news',0);
CREATE TABLE `wp_term_taxonomy` (`term_taxonomy_id` bigint unsigned NOT NULL,`term_id` bigint unsigned NOT NULL,`taxonomy` varchar(32),`description` longtext,`parent` bigint unsigned NOT NULL DEFAULT 0,`count` bigint NOT NULL DEFAULT 0,PRIMARY KEY (`term_taxonomy_id`)) DEFAULT CHARSET=utf8mb4;
INSERT INTO `wp_term_taxonomy` VALUES (1,1,'category','',0,1);
CREATE TABLE `wp_term_relationships` (`object_id` bigint unsigned NOT NULL,`term_taxonomy_id` bigint unsigned NOT NULL,`term_order` int NOT NULL DEFAULT 0,PRIMARY KEY (`object_id`,`term_taxonomy_id`)) DEFAULT CHARSET=utf8mb4;
INSERT INTO `wp_term_relationships` VALUES (1,1,0);
CREATE TABLE `wp_yoast_indexable` (`id` int unsigned NOT NULL,`object_id` bigint unsigned,`object_type` varchar(32),`title` text,PRIMARY KEY (`id`)) DEFAULT CHARSET=utf8mb4;
CREATE TABLE `wp_plugin_cache` (`id` int NOT NULL,`value` text) DEFAULT CHARSET=utf8mb4;
INSERT INTO `wp_plugin_cache` (`id`,`value`) VALUES (1,'cache','unsupported-extra-value https://incoming.test/cached http://base.test/legacy admin@www.incoming.test');
CREATE TABLE `wp_simple_history` (`id` bigint unsigned NOT NULL,`date` datetime NOT NULL,`logger` varchar(255),`level` varchar(20),`message` text,PRIMARY KEY (`id`)) DEFAULT CHARSET=utf8mb4;
INSERT INTO `wp_simple_history` VALUES (41,'2026-01-04 12:00:00','SimplePostLogger','info','Post updated');
CREATE TABLE `wp_simple_history_contexts` (`context_id` bigint unsigned NOT NULL,`history_id` bigint unsigned NOT NULL,`key` varchar(255),`value` longtext,PRIMARY KEY (`context_id`)) DEFAULT CHARSET=utf8mb4;
INSERT INTO `wp_simple_history_contexts` VALUES (81,41,'post_id','1'),(82,41,'url','a:1:{s:3:"url";s:32:"http://www.incoming.test/history";}');
COMMIT;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
