CREATE TABLE `mdl_task_adhoc` (
  `id` bigint(10) NOT NULL AUTO_INCREMENT,
  `component` varchar(255) NOT NULL DEFAULT '',
  `classname` varchar(255) NOT NULL DEFAULT '',
  `nextruntime` bigint(10) NOT NULL,
  `faildelay` bigint(10) DEFAULT NULL,
  `customdata` longtext DEFAULT NULL,
  `userid` bigint(10) DEFAULT NULL,
  `timecreated` bigint(10) NOT NULL DEFAULT 0,
  `timestarted` bigint(10) DEFAULT NULL,
  `hostname` varchar(255) DEFAULT NULL,
  `pid` bigint(10) DEFAULT NULL,
  `attemptsavailable` tinyint(2) DEFAULT NULL,
  `firststartingtime` bigint(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mdl_taskadho_nex_ix` (`nextruntime`),
  KEY `mdl_taskadho_tim_ix` (`timestarted`),
  KEY `mdl_taskadho_use_ix` (`userid`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED COMMENT='List of adhoc tasks waiting to run.';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mdl_task_adhoc`
--

LOCK TABLES `mdl_task_adhoc` WRITE;
/*!40000 ALTER TABLE `mdl_task_adhoc` DISABLE KEYS */;
/*!40000 ALTER TABLE `mdl_task_adhoc` ENABLE KEYS */;
UNLOCK TABLES;
