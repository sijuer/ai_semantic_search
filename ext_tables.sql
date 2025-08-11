
#
# Table structure for table 'pages'
# can be removed in typo3 13.0
#
CREATE TABLE pages (
        tx_semanticsearch_indexed tinyint(1) DEFAULT '0' NOT NULL,
        tx_semanticsearch_last_indexed int(11) DEFAULT '0' NOT NULL
);        