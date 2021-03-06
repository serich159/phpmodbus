<?php

namespace PHPModbus;

use Exception;

/**
 * Phpmodbus Copyright (c) 2004, 2013 Jan Krakora
 *
 * This source file is subject to the "PhpModbus license" that is bundled
 * with this package in the file license.txt.
 *
 *
 * @copyright  Copyright (c) 2004, 2013 Jan Krakora
 * @license    PhpModbus license
 * @category   Phpmodbus
 * @tutorial   Phpmodbus.pkg
 * @package    Phpmodbus
 * @version    $id$
 *
 */


/**
 * ModbusMaster
 *
 * This class deals with the MODBUS master
 *
 * Implemented MODBUS master functions:
 *   - FC  1: read coils
 *   - FC  2: read input discretes
 *   - FC  3: read multiple registers
 *   - FC  4: read multiple input registers
 *   - FC  5: write single coil
 *   - FC  6: write single register
 *   - FC 15: write multiple coils
 *   - FC 16: write multiple registers
 *   - FC 22: mask write register
 *   - FC 23: read write registers
 *
 * @author     Jan Krakora
 * @copyright  Copyright (c) 2004, 2013 Jan Krakora
 * @package    Phpmodbus
 *
 */
class ModbusMaster
{
	private $sock;

	public $host = "192.168.1.1";

	public $port = 502;

	public $client = "";

	public $client_port = 502;

	public $status;

	public $timeout_sec = 5; // Timeout 5 sec

	public $endianness = 0; // Endianness codding (little endian == 0, big endian == 1)

	public $socket_protocol = "UDP"; // Socket protocol (TCP, UDP, RTU_TCP)

	public $debug = false;

    /**
     * @var float $request_delay seconds to delay (or fraction there of) between requests. Needed for some older controllers.
     */
	public $request_delay = 0;

    /**
     * @var bool true if socket is connected
     */
    private $connected = false;

    /**
     * @var float microsecond of last communication - used for request delay.
     */
    private $last_request = 0;


	/**
	 * ModbusMaster
	 *
	 * This is the constructor that defines {@link $host} IP address of the object.
	 *
	 * @param String $host     An IP address of a Modbus TCP device. E.g. "192.168.1.1"
	 * @param String $protocol Socket protocol (TCP, UDP, RTU_TCP)
	 * @param Integer $port    Port number
	 */
	public function __construct($host, $protocol="UDP", $port=502)
	{
		$this->socket_protocol = $protocol;
		$this->host = $host;
		$this->port = $port;
	}

	/**
	 * __toString
	 *
	 * Magic method
	 */
	public function __toString()
	{
		return $this->status;
	}

	public function __destruct()
    {
        $this->disconnect();
    }

    /**
	 * connect
	 *
	 * Connect the socket
	 *
	 * @return bool
	 * @throws Exception
	 */
	public function connect()
	{
		// Create a protocol specific socket
		if ($this->socket_protocol == "TCP" || $this->socket_protocol == "RTU_TCP") {
			// TCP socket
			$this->sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		} elseif ($this->socket_protocol == "UDP") {
			// UDP socket
			$this->sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		} else {
			throw new Exception("Unknown socket protocol, should be 'TCP' or 'UDP'");
		}
		// Bind the client socket to a specific local port
		if (strlen($this->client) > 0) {
			$result = socket_bind($this->sock, $this->client, $this->client_port);
			if ($result === false) {
				throw new Exception("socket_bind() failed.\nReason: ($result)" .
					socket_strerror(socket_last_error($this->sock)));
			} else {
				$this->log( "Bound" );
			}
		}
		socket_set_nonblock($this->sock);
		// Socket settings
		socket_set_option($this->sock, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $this->timeout_sec, 'usec' => 0));
		socket_set_option($this->sock, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $this->timeout_sec, 'usec' => 0));
		$error = null;
		$attempts = 0;
		// Connect the socket
		while( !($result = @socket_connect($this->sock, $this->host, $this->port)) && $attempts++ < $this->timeout_sec*1000) {
			$error = socket_last_error();
			if($error == SOCKET_EISCONN) {
				$this->log( "Connected Mac Method" );
	            $this->connected = true;
	            socket_set_block($this->sock);
				return true;
			}
			if($error != SOCKET_EINPROGRESS && $error != SOCKET_EALREADY) {
				socket_close($this->sock);
				throw new Exception("Error connecting (attempt $attempts) Socket: ($error) ".socket_strerror($error));
			}
			usleep(1000);

		}

		if($result !== false) {
			$this->log("Connected Linux Method");
	        $this->connected = true;
	        socket_set_block($this->sock);
	        return true;
		}

	    $this->connected = false;
		throw new Exception("socket_connect() failed.\nTimeout or Reason: (".socket_last_error($this->sock).")" .
			socket_strerror(socket_last_error($this->sock)));
	}

	/**
	 * disconnect
	 *
	 * Disconnect the socket
	 */
	public function disconnect()
	{
		if($this->connected) {
			socket_close($this->sock);
	        $this->connected = false;
			$this->log( "Disconnected" );
		}
	}

	/**
	 * send
	 *
	 * Send the packet via Modbus
	 *
	 * @param string $packet
	 */
	private function send($packet)
	{
		if((microtime(true) - $this->last_request) < $this->request_delay) {
			$delay = round(1000000*($this->request_delay - (microtime(true) - $this->last_request)));
			$this->log( "Request too fast, sleeping for $delay");
			usleep($delay);
		}
		if($this->socket_protocol == "RTU_TCP") {
			// Trim TCP ADU
			$packet = substr($packet, 6);
			// LOOKUP AND APPEND CRC
			$append = $this->crc16($packet);
			$packet = $packet.$append;
		}
		$this->log( "Really Sending This Packet: ".$this->printPacket($packet) );
		socket_write($this->sock, $packet, strlen($packet));
		$this->log( "Send" );
		$this->last_request = microtime(true);
	}

	/**
	 * rec
	 *
	 * Receive data from the socket
	 *
	 * @return bool
	 * @throws Exception
	 */
	private function rec()
	{
		if($this->socket_protocol == "RTU_TCP") {
			usleep(250000);
		}
		socket_set_nonblock($this->sock);
		$readsocks[] = $this->sock;
		$writesocks = null;
		$exceptsocks = null;
		$rec = "";
		$lastAccess = time();
		while (socket_select($readsocks, $writesocks, $exceptsocks, 0, 300000) !== false) {
			//$this->log( "Wait data ... " );
			if (in_array($this->sock, $readsocks)) {
				//$this->log( "Try Read Data" );
				while (@socket_recv($this->sock, $rec, 2000, 0)) {
					$this->log( "Data received" );
					if($this->socket_protocol == "RTU_TCP") {
						// CHECK CRC, return null if bad CRC!
						$calc_crc = $this->crc16(substr($rec,0,-2));
						$rec_crc = substr($rec, -2);
						if($calc_crc != $rec_crc) {
							$this->log("Received CRC and Calc CRC do not match!");
							return null;
						}
						// TRIM RTU CRC, ADD DUMMY TCP ADU
						$packet = substr($rec,0,-2);
						$buffer3 = '';
						$buffer3 .= IecType::iecINT(rand(0, 65000));   // dummy transaction ID
						$buffer3 .= IecType::iecINT(0);               // protocol ID
						$buffer3 .= IecType::iecINT(strlen($packet));    // length
						return $buffer3.$packet;
					}
					return $rec;
				}
				$lastAccess = time();
			} else {
				if (time() - $lastAccess >= $this->timeout_sec) {
					throw new Exception("Watchdog time expired [ " .
						$this->timeout_sec . " sec]!!! Connection to " .
						$this->host . " is not established.");
				}
			}
			$readsocks[] = $this->sock;
		}
		$this->last_request = microtime(true);
		return null;
	}

	/**
	 * responseCode
	 *
	 * Check the Modbus response code
	 *
	 * @param string $packet
	 * @return bool
	 * @throws Exception
	 */
	private function responseCode($packet)
	{
		if ((ord($packet[7]) & 0x80) > 0) {
			// failure code
			$failure_code = ord($packet[8]);
			// failure code strings
			$failures = array(
				0x01 => "ILLEGAL FUNCTION",
				0x02 => "ILLEGAL DATA ADDRESS",
				0x03 => "ILLEGAL DATA VALUE",
				0x04 => "SLAVE DEVICE FAILURE",
				0x05 => "ACKNOWLEDGE",
				0x06 => "SLAVE DEVICE BUSY",
				0x08 => "MEMORY PARITY ERROR",
				0x0A => "GATEWAY PATH UNAVAILABLE",
				0x0B => "GATEWAY TARGET DEVICE FAILED TO RESPOND"
			);
			// get failure string
			if (key_exists($failure_code, $failures)) {
				$failure_str = $failures[$failure_code];
			} else {
				$failure_str = "UNDEFINED FAILURE CODE";
			}
			// exception response
			throw new Exception("Modbus response error code: $failure_code ($failure_str)");
		} else {
			$this->log( "Modbus response error code: NOERROR" );
			return true;
		}
	}

	/**
	 * readCoils
	 *
	 * Modbus function FC 1(0x01) - Read Coils
	 *
	 * Reads {@link $quantity} of Coils (boolean) from reference
	 * {@link $reference} of a memory of a Modbus device given by
	 * {@link $unitId}.
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $quantity
	 * @return bool[]
	 */
	public function readCoils($unitId, $reference, $quantity)
	{
		$this->log( "readCoils: START" );
		// connect
        $alreadyConnected = $this->connected;
        if (!$alreadyConnected) $this->connect();
		// send FC 1
		$packet = $this->readCoilsPacketBuilder($unitId, $reference, $quantity);
		$this->log( $this->printPacket($packet) );
		$this->send($packet);
		// receive response
		$rpacket = $this->rec();
		$this->log( $this->printPacket($rpacket) );
		// parse packet
		$receivedData = $this->readCoilsParser($rpacket, $quantity);
		// disconnect
		if (!$alreadyConnected) $this->disconnect();
		$this->log( "readCoils: DONE" );
		// return
		return $receivedData;
	}

	/**
	 * fc1
	 *
	 * Alias to {@link readCoils} method
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $quantity
	 * @return bool[]
	 */
	public function fc1($unitId, $reference, $quantity)
	{
		return $this->readCoils($unitId, $reference, $quantity);
	}

	/**
	 * readCoilsPacketBuilder
	 *
	 * FC1 packet builder - read coils
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $quantity
	 * @return string
	 */
	private function readCoilsPacketBuilder($unitId, $reference, $quantity)
	{
		$dataLen = 0;
		// build data section
		$buffer1 = "";
		// build body
		$buffer2 = "";
		$buffer2 .= IecType::iecBYTE(1);              // FC 1 = 1(0x01)
		// build body - read section
		$buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
		$buffer2 .= IecType::iecINT($quantity);       // quantity
		$dataLen += 5;
		// build header
		$buffer3 = '';
		$buffer3 .= IecType::iecINT(rand(0, 65000));   // transaction ID
		$buffer3 .= IecType::iecINT(0);               // protocol ID
		$buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
		$buffer3 .= IecType::iecBYTE($unitId);        //unit ID
		// return packet string
		return $buffer3 . $buffer2 . $buffer1;
	}

	/**
	 * readCoilsParser
	 *
	 * FC 1 response parser
	 *
	 * @param string $packet
	 * @param int    $quantity
	 * @return bool[]
	 */
	private function readCoilsParser($packet, $quantity)
	{
		$data = array();
		// check Response code
		$this->responseCode($packet);
		// get data from stream
		for ($i = 0; $i < ord($packet[8]); $i++) {
			$data[$i] = ord($packet[9 + $i]);
		}
		// get bool values to array
		$data_boolean_array = array();
		$di = 0;
		foreach ($data as $value) {
			for ($i = 0; $i < 8; $i++) {
				if ($di == $quantity) {
					continue;
				}
				// get boolean value
				$v = ($value >> $i) & 0x01;
				// build boolean array
				if ($v == 0) {
					$data_boolean_array[] = false;
				} else {
					$data_boolean_array[] = true;
				}
				$di++;
			}
		}
		return $data_boolean_array;
	}

	/**
	 * readInputDiscretes
	 *
	 * Modbus function FC 2(0x02) - Read Input Discretes
	 *
	 * Reads {@link $quantity} of Inputs (boolean) from reference
	 * {@link $reference} of a memory of a Modbus device given by
	 * {@link $unitId}.
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $quantity
	 * @return bool[]
	 */
	public function readInputDiscretes($unitId, $reference, $quantity)
	{
		$this->log( "readInputDiscretes: START" );
		// connect
        $alreadyConnected = $this->connected;
        if (!$alreadyConnected) $this->connect();
		// send FC 2
		$packet = $this->readInputDiscretesPacketBuilder($unitId, $reference, $quantity);
		$this->log( $this->printPacket($packet) );
		$this->send($packet);
		// receive response
		$rpacket = $this->rec();
		$this->log( $this->printPacket($rpacket) );
		// parse packet
		$receivedData = $this->readInputDiscretesParser($rpacket, $quantity);
		// disconnect
		if (!$alreadyConnected) $this->disconnect();
		$this->log( "readInputDiscretes: DONE" );
		// return
		return $receivedData;
	}

	/**
	 * fc2
	 *
	 * Alias to {@link readInputDiscretes} method
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $quantity
	 * @return bool[]
	 */
	public function fc2($unitId, $reference, $quantity)
	{
		return $this->readInputDiscretes($unitId, $reference, $quantity);
	}

	/**
	 * readInputDiscretesPacketBuilder
	 *
	 * FC2 packet builder - read coils
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $quantity
	 * @return string
	 */
	private function readInputDiscretesPacketBuilder($unitId, $reference, $quantity)
	{
		$dataLen = 0;
		// build data section
		$buffer1 = "";
		// build body
		$buffer2 = "";
		$buffer2 .= IecType::iecBYTE(2);              // FC 2 = 2(0x02)
		// build body - read section
		$buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
		$buffer2 .= IecType::iecINT($quantity);       // quantity
		$dataLen += 5;
		// build header
		$buffer3 = '';
		$buffer3 .= IecType::iecINT(rand(0, 65000));   // transaction ID
		$buffer3 .= IecType::iecINT(0);               // protocol ID
		$buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
		$buffer3 .= IecType::iecBYTE($unitId);        //unit ID
		// return packet string
		return $buffer3 . $buffer2 . $buffer1;
	}

	/**
	 * readInputDiscretesParser
	 *
	 * FC 2 response parser, alias to FC 1 parser i.e. readCoilsParser.
	 *
	 * @param string $packet
	 * @param int    $quantity
	 * @return bool[]
	 */
	private function readInputDiscretesParser($packet, $quantity)
	{
		return $this->readCoilsParser($packet, $quantity);
	}

	/**
	 * readMultipleRegisters
	 *
	 * Modbus function FC 3(0x03) - Read Multiple Registers.
	 *
	 * This function reads {@link $quantity} of Words (2 bytes) from reference
	 * {@link $referenceRead} of a memory of a Modbus device given by
	 * {@link $unitId}.
	 *
	 *
	 * @param int $unitId    usually ID of Modbus device
	 * @param int $reference Reference in the device memory to read data (e.g. in device WAGO 750-841, memory MW0
	 *                       starts at address 12288).
	 * @param int $quantity  Amounth of the data to be read from device.
	 * @return false|array Success flag or array of received data.
	 */
	public function readMultipleRegisters($unitId, $reference, $quantity)
	{
		$this->log( "readMultipleRegisters: START" );
		// connect
        $alreadyConnected = $this->connected;
        if (!$alreadyConnected) $this->connect();
		// send FC 3
		$packet = $this->readMultipleRegistersPacketBuilder($unitId, $reference, $quantity);
		$this->log( $this->printPacket($packet) );
		$this->send($packet);
		// receive response
		$rpacket = $this->rec();
		$this->log( $this->printPacket($rpacket) );
		// parse packet
		$receivedData = $this->readMultipleRegistersParser($rpacket);
		// disconnect
		if (!$alreadyConnected) $this->disconnect();
		$this->log( "readMultipleRegisters: DONE" );
		// return
		return $receivedData;
	}

	/**
	 * fc3
	 *
	 * Alias to {@link readMultipleRegisters} method.
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $quantity
	 * @return false|array
	 */
	public function fc3($unitId, $reference, $quantity)
	{
		return $this->readMultipleRegisters($unitId, $reference, $quantity);
	}

	/**
	 * readMultipleRegistersPacketBuilder
	 *
	 * Packet FC 3 builder - read multiple registers
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $quantity
	 * @return string
	 */
	private function readMultipleRegistersPacketBuilder($unitId, $reference, $quantity)
	{
		$dataLen = 0;
		// build data section
		$buffer1 = "";
		// build body
		$buffer2 = "";
		$buffer2 .= IecType::iecBYTE(3);             // FC 3 = 3(0x03)
		// build body - read section
		$buffer2 .= IecType::iecINT($reference);  // refnumber = 12288
		$buffer2 .= IecType::iecINT($quantity);       // quantity
		$dataLen += 5;
		// build header
		$buffer3 = '';
		$buffer3 .= IecType::iecINT(rand(0, 65000));   // transaction ID
		$buffer3 .= IecType::iecINT(0);               // protocol ID
		$buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
		$buffer3 .= IecType::iecBYTE($unitId);        //unit ID
		// return packet string
		return $buffer3 . $buffer2 . $buffer1;
	}

	/**
	 * readMultipleRegistersParser
	 *
	 * FC 3 response parser
	 *
	 * @param string $packet
	 * @return array
	 */
	private function readMultipleRegistersParser($packet)
	{
		$data = array();
		// check Response code
		$this->responseCode($packet);
		// get data
		for ($i = 0; $i < ord($packet[8]); $i++) {
			$data[$i] = ord($packet[9 + $i]);
		}
		return $data;
	}

	/**
	 * readMultipleInputRegisters
	 *
	 * Modbus function FC 4(0x04) - Read Multiple Input Registers.
	 *
	 * This function reads {@link $quantity} of Words (2 bytes) from reference
	 * {@link $referenceRead} of a memory of a Modbus device given by
	 * {@link $unitId}.
	 *
	 *
	 * @param int $unitId    usually ID of Modbus device
	 * @param int $reference Reference in the device memory to read data.
	 * @param int $quantity  Amounth of the data to be read from device.
	 * @return false|array Success flag or array of received data.
	 */
	public function readMultipleInputRegisters($unitId, $reference, $quantity)
	{
		$this->log( "readMultipleInputRegisters: START" );
		// connect
        $alreadyConnected = $this->connected;
        if (!$alreadyConnected) $this->connect();
		// send FC 4
		$packet = $this->readMultipleInputRegistersPacketBuilder($unitId, $reference, $quantity);
		$this->log( $this->printPacket($packet) );
		$this->send($packet);
		// receive response
		$rpacket = $this->rec();
		$this->log( $this->printPacket($rpacket) );
		// parse packet
		$receivedData = $this->readMultipleInputRegistersParser($rpacket);
		// disconnect
		if (!$alreadyConnected) $this->disconnect();
		$this->log( "readMultipleInputRegisters: DONE" );
		// return
		return $receivedData;
	}

	/**
	 * fc4
	 *
	 * Alias to {@link readMultipleInputRegisters} method.
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $quantity
	 * @return false|array
	 */
	public function fc4($unitId, $reference, $quantity)
	{
		return $this->readMultipleInputRegisters($unitId, $reference, $quantity);
	}

	/**
	 * readMultipleInputRegistersPacketBuilder
	 *
	 * Packet FC 4 builder - read multiple input registers
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $quantity
	 * @return string
	 */
	private function readMultipleInputRegistersPacketBuilder($unitId, $reference, $quantity)
	{
		$dataLen = 0;
		// build data section
		$buffer1 = "";
		// build body
		$buffer2 = "";
		$buffer2 .= IecType::iecBYTE(4);                                                // FC 4 = 4(0x04)
		// build body - read section
		$buffer2 .= IecType::iecINT($reference);                                        // refnumber = 12288
		$buffer2 .= IecType::iecINT($quantity);                                         // quantity
		$dataLen += 5;
		// build header
		$buffer3 = '';
		$buffer3 .= IecType::iecINT(rand(0, 65000));                                     // transaction ID
		$buffer3 .= IecType::iecINT(0);                                                 // protocol ID
		$buffer3 .= IecType::iecINT($dataLen + 1);                                      // lenght
		$buffer3 .= IecType::iecBYTE($unitId);                                          // unit ID
		// return packet string
		return $buffer3 . $buffer2 . $buffer1;
	}

	/**
	 * readMultipleInputRegistersParser
	 *
	 * FC 4 response parser
	 *
	 * @param string $packet
	 * @return array
	 */
	private function readMultipleInputRegistersParser($packet)
	{
		$data = array();
		// check Response code
		$this->responseCode($packet);
		// get data
		for ($i = 0; $i < ord($packet[8]); $i++) {
			$data[$i] = ord($packet[9 + $i]);
		}
		return $data;
	}

	/**
	 * writeSingleCoil
	 *
	 * Modbus function FC5(0x05) - Write Single Register.
	 *
	 * This function writes {@link $data} single coil at {@link $reference} position of
	 * memory of a Modbus device given by {@link $unitId}.
	 *
	 *
	 * @param int   $unitId    usually ID of Modbus device
	 * @param int   $reference Reference in the device memory (e.g. in device WAGO 750-841, memory MW0 starts at
	 *                         address 12288)
	 * @param array $data      value to be written (TRUE|FALSE).
	 * @return bool Success flag
	 */
	public function writeSingleCoil($unitId, $reference, $data)
	{
		$this->log( "writeSingleCoil: START" );
		// connect
        $alreadyConnected = $this->connected;
        if (!$alreadyConnected) $this->connect();
		// send FC5
		$packet = $this->writeSingleCoilPacketBuilder($unitId, $reference, $data);
		$this->log( $this->printPacket($packet) );
		$this->send($packet);
		// receive response
		$rpacket = $this->rec();
		$this->log( $this->printPacket($rpacket) );
		// parse packet
		$this->writeSingleCoilParser($rpacket);
		// disconnect
		if (!$alreadyConnected) $this->disconnect();
		$this->log( "writeSingleCoil: DONE" );
		return true;
	}

	/**
	 * fc5
	 *
	 * Alias to {@link writeSingleCoil} method
	 *
	 * @param int   $unitId
	 * @param int   $reference
	 * @param array $data
	 * @return bool
	 */
	public function fc5($unitId, $reference, $data)
	{
		return $this->writeSingleCoil($unitId, $reference, $data);
	}

	/**
	 * writeSingleCoilPacketBuilder
	 *
	 * Packet builder FC5 - WRITE single register
	 *
	 * @param int   $unitId
	 * @param int   $reference
	 * @param array $data
	 * @return string
	 */
	private function writeSingleCoilPacketBuilder($unitId, $reference, $data)
	{
		$dataLen = 0;
		// build data section
		$buffer1 = "";
		foreach ($data as $key => $dataitem) {
			if ($dataitem == true) {
				$buffer1 = IecType::iecINT(0xFF00);
			} else {
				$buffer1 = IecType::iecINT(0x0000);
			};
		};
		$dataLen += 2;
		// build body
		$buffer2 = "";
		$buffer2 .= IecType::iecBYTE(5);             // FC5 = 5(0x05)
		$buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
		$dataLen += 3;
		// build header
		$buffer3 = '';
		$buffer3 .= IecType::iecINT(rand(0, 65000));   // transaction ID
		$buffer3 .= IecType::iecINT(0);               // protocol ID
		$buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
		$buffer3 .= IecType::iecBYTE($unitId);        //unit ID

		// return packet string
		return $buffer3 . $buffer2 . $buffer1;
	}

	/**
	 * writeSingleCoilParser
	 *
	 * FC5 response parser
	 *
	 * @param string $packet
	 * @return bool
	 */
	private function writeSingleCoilParser($packet)
	{
		$this->responseCode($packet);
		return true;
	}

	/**
	 * writeSingleRegister
	 *
	 * Modbus function FC6(0x06) - Write Single Register.
	 *
	 * This function writes {@link $data} single word value at {@link $reference} position of
	 * memory of a Modbus device given by {@link $unitId}.
	 *
	 *
	 * @param int   $unitId    usually ID of Modbus device
	 * @param int   $reference Reference in the device memory (e.g. in device WAGO 750-841, memory MW0 starts at
	 *                         address 12288)
	 * @param array $data      Array of values to be written.
	 * @return bool Success flag
	 */
	public function writeSingleRegister($unitId, $reference, $data)
	{
		$this->log( "writeSingleRegister: START" );
		// connect
        $alreadyConnected = $this->connected;
        if (!$alreadyConnected) $this->connect();
		// send FC6
		$packet = $this->writeSingleRegisterPacketBuilder($unitId, $reference, $data);
		$this->log( $this->printPacket($packet) );
		$this->send($packet);
		// receive response
		$rpacket = $this->rec();
		$this->log( $this->printPacket($rpacket) );
		// parse packet
		$this->writeSingleRegisterParser($rpacket);
		// disconnect
		if (!$alreadyConnected) $this->disconnect();
		$this->log( "writeSingleRegister: DONE" );
		return true;
	}

	/**
	 * fc6
	 *
	 * Alias to {@link writeSingleRegister} method
	 *
	 * @param int   $unitId
	 * @param int   $reference
	 * @param array $data
	 * @return bool
	 */
	public function fc6($unitId, $reference, $data)
	{
		return $this->writeSingleRegister($unitId, $reference, $data);
	}

	/**
	 * writeSingleRegisterPacketBuilder
	 *
	 * Packet builder FC6 - WRITE single register
	 *
	 * @param int   $unitId
	 * @param int   $reference
	 * @param array $data
	 * @return string
	 */
	private function writeSingleRegisterPacketBuilder($unitId, $reference, $data)
	{
		$dataLen = 0;
		// build data section
		$buffer1 = "";
		foreach ($data as $key => $dataitem) {
			$buffer1 .= IecType::iecINT($dataitem);   // register values x
			$dataLen += 2;
			break;
		}
		// build body
		$buffer2 = "";
		$buffer2 .= IecType::iecBYTE(6);             // FC6 = 6(0x06)
		$buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
		$dataLen += 3;
		// build header
		$buffer3 = '';
		$buffer3 .= IecType::iecINT(rand(0, 65000));   // transaction ID
		$buffer3 .= IecType::iecINT(0);               // protocol ID
		$buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
		$buffer3 .= IecType::iecBYTE($unitId);        //unit ID

		// return packet string
		return $buffer3 . $buffer2 . $buffer1;
	}

	/**
	 * writeSingleRegisterParser
	 *
	 * FC6 response parser
	 *
	 * @param string $packet
	 * @return bool
	 */
	private function writeSingleRegisterParser($packet)
	{
		$this->responseCode($packet);
		return true;
	}

	/**
	 * writeMultipleCoils
	 *
	 * Modbus function FC15(0x0F) - Write Multiple Coils
	 *
	 * This function writes {@link $data} array at {@link $reference} position of
	 * memory of a Modbus device given by {@link $unitId}.
	 *
	 * @param int   $unitId
	 * @param int   $reference
	 * @param array $data
	 * @return bool
	 */
	public function writeMultipleCoils($unitId, $reference, $data)
	{
		$this->log( "writeMultipleCoils: START" );
		// connect
        $alreadyConnected = $this->connected;
        if (!$alreadyConnected) $this->connect();
		// send FC15
		$packet = $this->writeMultipleCoilsPacketBuilder($unitId, $reference, $data);
		$this->log( $this->printPacket($packet) );
		$this->send($packet);
		// receive response
		$rpacket = $this->rec();
		$this->log( $this->printPacket($rpacket) );
		// parse packet
		$this->writeMultipleCoilsParser($rpacket);
		// disconnect
		if (!$alreadyConnected) $this->disconnect();
		$this->log( "writeMultipleCoils: DONE" );
		return true;
	}

	/**
	 * fc15
	 *
	 * Alias to {@link writeMultipleCoils} method
	 *
	 * @param int   $unitId
	 * @param int   $reference
	 * @param array $data
	 * @return bool
	 */
	public function fc15($unitId, $reference, $data)
	{
		return $this->writeMultipleCoils($unitId, $reference, $data);
	}

	/**
	 * writeMultipleCoilsPacketBuilder
	 *
	 * Packet builder FC15 - Write multiple coils
	 *
	 * @param int   $unitId
	 * @param int   $reference
	 * @param array $data
	 * @return string
	 */
	private function writeMultipleCoilsPacketBuilder($unitId, $reference, $data)
	{
		$dataLen = 0;
		// build bool stream to the WORD array
		$data_word_stream = array();
		$data_word = 0;
		$shift = 0;
		for ($i = 0; $i < count($data); $i++) {
			if ((($i % 8) == 0) && ($i > 0)) {
				$data_word_stream[] = $data_word;
				$shift = 0;
				$data_word = 0;
				$data_word |= (0x01 && $data[$i]) << $shift;
				$shift++;
			} else {
				$data_word |= (0x01 && $data[$i]) << $shift;
				$shift++;
			}
		}
		$data_word_stream[] = $data_word;
		// show binary stream to status string
		foreach ($data_word_stream as $d) {
			$this->log( sprintf("byte=b%08b\n", $d) );
		}
		// build data section
		$buffer1 = "";
		foreach ($data_word_stream as $key => $dataitem) {
			$buffer1 .= IecType::iecBYTE($dataitem);   // register values x
			$dataLen += 1;
		}
		// build body
		$buffer2 = "";
		$buffer2 .= IecType::iecBYTE(15);             // FC 15 = 15(0x0f)
		$buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
		$buffer2 .= IecType::iecINT(count($data));      // bit count
		$buffer2 .= IecType::iecBYTE((count($data) + 7) / 8);       // byte count
		$dataLen += 6;
		// build header
		$buffer3 = '';
		$buffer3 .= IecType::iecINT(rand(0, 65000));   // transaction ID
		$buffer3 .= IecType::iecINT(0);               // protocol ID
		$buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
		$buffer3 .= IecType::iecBYTE($unitId);        // unit ID

		// return packet string
		return $buffer3 . $buffer2 . $buffer1;
	}

	/**
	 * writeMultipleCoilsParser
	 *
	 * FC15 response parser
	 *
	 * @param string $packet
	 * @return bool
	 */
	private function writeMultipleCoilsParser($packet)
	{
		$this->responseCode($packet);
		return true;
	}

	/**
	 * writeMultipleRegister
	 *
	 * Modbus function FC16(0x10) - Write Multiple Register.
	 *
	 * This function writes {@link $data} array at {@link $reference} position of
	 * memory of a Modbus device given by {@link $unitId}.
	 *
	 *
	 * @param int   $unitId    usually ID of Modbus device
	 * @param int   $reference Reference in the device memory (e.g. in device WAGO 750-841, memory MW0 starts at
	 *                         address 12288)
	 * @param array $data      Array of values to be written.
	 * @param array $dataTypes Array of types of values to be written. The array should consists of string "INT",
	 *                         "DINT" and "REAL".
	 * @return bool Success flag
	 */
	public function writeMultipleRegister($unitId, $reference, $data, $dataTypes)
	{
		$this->log( "writeMultipleRegister: START" );
		// connect
        $alreadyConnected = $this->connected;
        if (!$alreadyConnected) $this->connect();
		// send FC16
		$packet = $this->writeMultipleRegisterPacketBuilder($unitId, $reference, $data, $dataTypes);
		$this->log( $this->printPacket($packet) );
		$this->send($packet);
		// receive response
		$rpacket = $this->rec();
		$this->log( $this->printPacket($rpacket) );
		// parse packet
		$this->writeMultipleRegisterParser($rpacket);
		// disconnect
		if (!$alreadyConnected) $this->disconnect();
		$this->log( "writeMultipleRegister: DONE" );
		return true;
	}

	/**
	 * fc16
	 *
	 * Alias to {@link writeMultipleRegister} method
	 *
	 * @param int   $unitId
	 * @param int   $reference
	 * @param array $data
	 * @param array $dataTypes
	 * @return bool
	 */
	public function fc16($unitId, $reference, $data, $dataTypes)
	{
		return $this->writeMultipleRegister($unitId, $reference, $data, $dataTypes);
	}

	/**
	 * writeMultipleRegisterPacketBuilder
	 *
	 * Packet builder FC16 - WRITE multiple register
	 *     e.g.: 4dd90000000d0010300000030603e807d00bb8
	 *
	 * @param int   $unitId
	 * @param int   $reference
	 * @param array $data
	 * @param array $dataTypes
	 * @return string
	 */
	private function writeMultipleRegisterPacketBuilder($unitId, $reference, $data, $dataTypes)
	{
		$dataLen = 0;
		// build data section
		$buffer1 = "";
		foreach ($data as $key => $dataitem) {
			if ($dataTypes[$key] == "INT") {
				$buffer1 .= IecType::iecINT($dataitem);   // register values x
				$dataLen += 2;
			} elseif ($dataTypes[$key] == "DINT") {
				$buffer1 .= IecType::iecDINT($dataitem, $this->endianness);   // register values x
				$dataLen += 4;
			} elseif ($dataTypes[$key] == "REAL") {
				$buffer1 .= IecType::iecREAL($dataitem, $this->endianness);   // register values x
				$dataLen += 4;
			} else {
				$buffer1 .= IecType::iecINT($dataitem);   // register values x
				$dataLen += 2;
			}
		}
		// build body
		$buffer2 = "";
		$buffer2 .= IecType::iecBYTE(16);             // FC 16 = 16(0x10)
		$buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
		$buffer2 .= IecType::iecINT($dataLen / 2);        // word count
		$buffer2 .= IecType::iecBYTE($dataLen);     // byte count
		$dataLen += 6;
		// build header
		$buffer3 = '';
		$buffer3 .= IecType::iecINT(rand(0, 65000));   // transaction ID
		$buffer3 .= IecType::iecINT(0);               // protocol ID
		$buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
		$buffer3 .= IecType::iecBYTE($unitId);        //unit ID

		// return packet string
		return $buffer3 . $buffer2 . $buffer1;
	}

	/**
	 * writeMultipleRegisterParser
	 *
	 * FC16 response parser
	 *
	 * @param string $packet
	 * @return bool
	 */
	private function writeMultipleRegisterParser($packet)
	{
		$this->responseCode($packet);
		return true;
	}

	/**
	 * maskWriteRegister
	 *
	 * Modbus function FC22(0x16) - Mask Write Register.
	 *
	 * This function alter single bit(s) at {@link $reference} position of
	 * memory of a Modbus device given by {@link $unitId}.
	 *
	 * Result = (Current Contents AND And_Mask) OR (Or_Mask AND (NOT And_Mask))
	 *
	 * @param int $unitId    usually ID of Modbus device
	 * @param int $reference Reference in the device memory (e.g. in device WAGO 750-841, memory MW0 starts at address
	 *                       12288)
	 * @param int $andMask
	 * @param int $orMask
	 * @return bool Success flag
	 */
	public function maskWriteRegister($unitId, $reference, $andMask, $orMask)
	{
		$this->log( "maskWriteRegister: START" );
		// connect
        $alreadyConnected = $this->connected;
        if (!$alreadyConnected) $this->connect();
		// send FC22
		$packet = $this->maskWriteRegisterPacketBuilder($unitId, $reference, $andMask, $orMask);
		$this->log( $this->printPacket($packet) );
		$this->send($packet);
		// receive response
		$rpacket = $this->rec();
		$this->log( $this->printPacket($rpacket) );
		// parse packet
		$this->maskWriteRegisterParser($rpacket);
		// disconnect
		if (!$alreadyConnected) $this->disconnect();
		$this->log( "maskWriteRegister: DONE" );
		return true;
	}

	/**
	 * fc22
	 *
	 * Alias to {@link maskWriteRegister} method
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $andMask
	 * @param int $orMask
	 * @return bool
	 */
	public function fc22($unitId, $reference, $andMask, $orMask)
	{
		return $this->maskWriteRegister($unitId, $reference, $andMask, $orMask);
	}

	/**
	 * maskWriteRegisterPacketBuilder
	 *
	 * Packet builder FC22 - MASK WRITE register
	 *
	 * @param int $unitId
	 * @param int $reference
	 * @param int $andMask
	 * @param int $orMask
	 * @return string
	 */
	private function maskWriteRegisterPacketBuilder($unitId, $reference, $andMask, $orMask)
	{
		$dataLen = 0;
		// build data section
		$buffer1 = "";
		// build body
		$buffer2 = "";
		$buffer2 .= IecType::iecBYTE(22);             // FC 22 = 22(0x16)
		$buffer2 .= IecType::iecINT($reference);      // refnumber = 12288
		$buffer2 .= IecType::iecINT($andMask);        // AND mask
		$buffer2 .= IecType::iecINT($orMask);          // OR mask
		$dataLen += 7;
		// build header
		$buffer3 = '';
		$buffer3 .= IecType::iecINT(rand(0, 65000));   // transaction ID
		$buffer3 .= IecType::iecINT(0);               // protocol ID
		$buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
		$buffer3 .= IecType::iecBYTE($unitId);        //unit ID
		// return packet string
		return $buffer3 . $buffer2 . $buffer1;
	}

	/**
	 * maskWriteRegisterParser
	 *
	 * FC22 response parser
	 *
	 * @param string $packet
	 * @return bool
	 */
	private function maskWriteRegisterParser($packet)
	{
		$this->responseCode($packet);
		return true;
	}

	/**
	 * readWriteRegisters
	 *
	 * Modbus function FC23(0x17) - Read Write Registers.
	 *
	 * This function writes {@link $data} array at reference {@link $referenceWrite}
	 * position of memory of a Modbus device given by {@link $unitId}. Simultanously,
	 * it returns {@link $quantity} of Words (2 bytes) from reference {@link $referenceRead}.
	 *
	 *
	 * @param int   $unitId         usually ID of Modbus device
	 * @param int   $referenceRead  Reference in the device memory to read data (e.g. in device WAGO 750-841, memory
	 *                              MW0 starts at address 12288).
	 * @param int   $quantity       Amounth of the data to be read from device.
	 * @param int   $referenceWrite Reference in the device memory to write data.
	 * @param array $data           Array of values to be written.
	 * @param array $dataTypes      Array of types of values to be written. The array should consists of string "INT",
	 *                              "DINT" and "REAL".
	 * @return false|array Success flag or array of data.
	 */
	public function readWriteRegisters($unitId, $referenceRead, $quantity, $referenceWrite, $data, $dataTypes)
	{
		$this->log( "readWriteRegisters: START" );
		// connect
        $alreadyConnected = $this->connected;
        if (!$alreadyConnected) $this->connect();
		// send FC23
		$packet = $this->readWriteRegistersPacketBuilder($unitId, $referenceRead, $quantity, $referenceWrite, $data,
			$dataTypes);
		$this->log( $this->printPacket($packet) );
		$this->send($packet);
		// receive response
		$rpacket = $this->rec();
		$this->log( $this->printPacket($rpacket) );
		// parse packet
		$receivedData = $this->readWriteRegistersParser($rpacket);
		// disconnect
		if (!$alreadyConnected) $this->disconnect();
		$this->log( "writeMultipleRegister: DONE" );
		// return
		return $receivedData;
	}

	/**
	 * fc23
	 *
	 * Alias to {@link readWriteRegisters} method.
	 *
	 * @param int   $unitId
	 * @param int   $referenceRead
	 * @param int   $quantity
	 * @param int   $referenceWrite
	 * @param array $data
	 * @param array $dataTypes
	 * @return false|array
	 */
	public function fc23($unitId, $referenceRead, $quantity, $referenceWrite, $data, $dataTypes)
	{
		return $this->readWriteRegisters($unitId, $referenceRead, $quantity, $referenceWrite, $data, $dataTypes);
	}

	/**
	 * readWriteRegistersPacketBuilder
	 *
	 * Packet FC23 builder - READ WRITE registers
	 *
	 *
	 * @param int   $unitId
	 * @param int   $referenceRead
	 * @param int   $quantity
	 * @param int   $referenceWrite
	 * @param array $data
	 * @param array $dataTypes
	 * @return string
	 */
	private function readWriteRegistersPacketBuilder(
		$unitId,
		$referenceRead,
		$quantity,
		$referenceWrite,
		$data,
		$dataTypes
	) {
		$dataLen = 0;
		// build data section
		$buffer1 = "";
		foreach ($data as $key => $dataitem) {
			if ($dataTypes[$key] == "INT") {
				$buffer1 .= IecType::iecINT($dataitem);   // register values x
				$dataLen += 2;
			} elseif ($dataTypes[$key] == "DINT") {
				$buffer1 .= IecType::iecDINT($dataitem, $this->endianness);   // register values x
				$dataLen += 4;
			} elseif ($dataTypes[$key] == "REAL") {
				$buffer1 .= IecType::iecREAL($dataitem, $this->endianness);   // register values x
				$dataLen += 4;
			} else {
				$buffer1 .= IecType::iecINT($dataitem);   // register values x
				$dataLen += 2;
			}
		}
		// build body
		$buffer2 = "";
		$buffer2 .= IecType::iecBYTE(23);             // FC 23 = 23(0x17)
		// build body - read section
		$buffer2 .= IecType::iecINT($referenceRead);  // refnumber = 12288
		$buffer2 .= IecType::iecINT($quantity);       // quantity
		// build body - write section
		$buffer2 .= IecType::iecINT($referenceWrite); // refnumber = 12288
		$buffer2 .= IecType::iecINT($dataLen / 2);      // word count
		$buffer2 .= IecType::iecBYTE($dataLen);       // byte count
		$dataLen += 10;
		// build header
		$buffer3 = '';
		$buffer3 .= IecType::iecINT(rand(0, 65000));   // transaction ID
		$buffer3 .= IecType::iecINT(0);               // protocol ID
		$buffer3 .= IecType::iecINT($dataLen + 1);    // lenght
		$buffer3 .= IecType::iecBYTE($unitId);        //unit ID

		// return packet string
		return $buffer3 . $buffer2 . $buffer1;
	}

	/**
	 * readWriteRegistersParser
	 *
	 * FC23 response parser
	 *
	 * @param string $packet
	 * @return array
	 */
	private function readWriteRegistersParser($packet)
	{
		$data = array();
		// if not exception
		if (!$this->responseCode($packet)) {
			return false;
		}
		// get data
		for ($i = 0; $i < ord($packet[8]); $i++) {
			$data[$i] = ord($packet[9 + $i]);
		}
		return $data;
	}

	/**
	 * byte2hex
	 *
	 * Parse data and get it to the Hex form
	 *
	 * @param int $value
	 * @return string
	 */
	private function byte2hex($value)
	{
		$h = dechex(($value >> 4) & 0x0F);
		$l = dechex($value & 0x0F);
		return "$h$l";
	}

	/**
	 * printPacket
	 *
	 * Print a packet in the hex form
	 *
	 * @param string $packet
	 * @return string
	 */
	private function printPacket($packet)
	{
		$str = "";
		$str .= "Packet: ";
		for ($i = 0; $i < strlen($packet); $i++) {
			$str .= $this->byte2hex(ord($packet[$i]));
		}
		$str .= "\n";
		return $str;
	}

	private function log($txt)
	{
		$this->status .= $txt."\n";
		if($this->debug) echo date( DATE_RFC822 ). " - $txt\n";
	}

	public function crc16($data)
	{
		$crc = 0xFFFF;
		for ($i = 0; $i < strlen($data); $i++)
		{
			$crc ^=ord($data[$i]);
     		for ($j = 8; $j !=0; $j--)
			{
				if (($crc & 0x0001) !=0)
				{
					$crc >>= 1;
					$crc ^= 0xA001;
				}
				else
				$crc >>= 1;
			}
		}
		$highCrc=floor($crc/256);
		$lowCrc=($crc-$highCrc*256);
		return chr($lowCrc).chr($highCrc);
	}

}
