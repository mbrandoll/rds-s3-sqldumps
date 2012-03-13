<?php
class MySQLBackup {

	const SLEEP_BETWEEN_REQUESTS = 5;
	
	/**
	 * tmp dir to save files
	 * 
	 * @var string
	 */
	private $_tmp_path = null;
	
	/**
	 * source options
	 * 
	 * @var array
	 */
	private $_source = array();
	
	/**
	 * target options
	 * 
	 * @var array
	 */
	private $_target = array();
	


	/**
	 * backup filename
	 * 
	 * @var string
	 */
	private $_filename = null;
	
	
	/**
	 * mysql dump command path
	 * 
	 * @var string
	 */
	private $_mysqldump = null;
	

	/**
	 * 
	 * @param array $source
	 * @param array $target
	 * @param array $options
	 */
	public function __construct(array $source, array $target, $options = array())
	{
	
		if (isset($config['tmp_path']) && !empty($options['tmp_path'])) {
			$this->_tmp_path = realpath($options['tmp_path']) . '/';
		} else {
			$this->_tmp_path = '/tmp/';
		}
				
		$this->_source = $source;
		$this->_target = $target;		
	
		$this->_filename = $source['host'] . '_' . date('Y-m-d_Hi').".sql";
		
		if (isset($options['mysqldump_path']) && !empty($options['mysqldump_path'])) {
			$this->_mysqldump = $options['mysqldump_path'];
		} else {
			$this->_mysqldump = '/usr/bin/';
		}
	}
	
	
	/**
	 * execute the entire database process
	 */
	public function execute()
	{
		try {
			$this->databaseDump();
			$this->uploadToS3();
							
		} catch (\Exception $e) {
			$this->cleanup();
		}
		
		$this->cleanup();
	}
	

	
	/**
	 * Dump the database into a temp local file
	 */
	private function databaseDump()
	{
		// make sure we have a mysql connection
		$ready = false;
		$tries = 0;
		
		while (!$ready && $tries<=20) {
			$link = mysql_connect($this->_source['host'] . ':' . $this->_source['port'], $this->_source['username'], $this->_source['password']);
				
			if (!$link) {
				echo 'Unable to connect to RDS replica - ' . mysql_error();
			} else {
				$ready = true;
			}
			
			sleep(self::SLEEP_BETWEEN_REQUESTS);
				
			$tries++;
		}
		
		
		// dump the database
		$mysqldump_command = 
				$this->_mysqldump . 'mysqldump ' . 
				' --host=' . escapeshellarg($this->_source['host']) .
				' --port=' . escapeshellarg($this->_source['port']) .
				' --user=' . escapeshellarg($this->_source['username']) .
				' --password=' . escapeshellarg($this->_source['password']) .
				' --all-databases  --skip-lock-tables --debug-info ' .
				' > ' . escapeshellarg($this->_tmp_path . $this->_filename);
	
		$output = '';
		$return_var = 0;
		echo $mysqldump_command . PHP_EOL;
		
		exec($mysqldump_command, $output, $return_var);
		if ($return_var == 2) {
			throw new \Exception('mysqldump dump failed - ' . $mysqldump_command . ' - ' . print_R($output, true));
		}
	
		
	
		// gzip the results
		$gzip_command = 'tar -zcf ' . escapeshellarg($this->_tmp_path . $this->_filename . '.tar.gz') . ' -C ' . escapeshellarg($this->_tmp_path) . ' ' . escapeshellarg($this->_filename);
		exec($gzip_command);
		echo $gzip_command . PHP_EOL;
		
	}
	
	/**
	 * Upload to amazon s3 bucket.
	 */
	private function uploadToS3()
	{
		$options = array('key' => $this->_target['key'], 'secret' => $this->_target['secret']);
		$s3 = new \AmazonS3($options);
		$s3->disable_ssl_verification(false);
				
		$bucket_name = $this->_target['instance_id'];
	
		// create a new bucket, if not exists already
		try {
			$response = $s3->create_bucket($bucket_name, \AmazonS3::REGION_US_E1, \AmazonS3::ACL_PRIVATE);
		} catch (\Exception $e) {
			
		}

		// move the file
		$opt = array (
			'fileUpload' => $this->_tmp_path . $this->_filename . '.tar.gz',
			'acl' => \AmazonS3::ACL_PRIVATE
		);
			
		try {
			$response = $s3->create_object($bucket_name, $this->_filename . '.tar.gz', $opt);
		} catch (\Exception $e) {
			
		}
		
		if ($response->isOK()) {
			return true;
		} else {
			throw new \Exception('s3 upload failed - ' . print_r($response, true));
		}
	}
	
	
	/**
	 * remove the rds backup instance, remove the snapshot and remove the security group
	 */
	private function cleanup()
	{
	
		unlink($this->_tmp_path . $this->_filename);
		unlink($this->_tmp_path . $this->_filename . '.tar.gz');
		
	}
	
}