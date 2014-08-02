<?php
// Copyright (c) 2014, The Monero Project
// 
// All rights reserved.
// 
// Redistribution and use in source and binary forms, with or without modification, are
// permitted provided that the following conditions are met:
// 
// 1. Redistributions of source code must retain the above copyright notice, this list of
//    conditions and the following disclaimer.
// 
// 2. Redistributions in binary form must reproduce the above copyright notice, this list
//    of conditions and the following disclaimer in the documentation and/or other
//    materials provided with the distribution.
// 
// 3. Neither the name of the copyright holder nor the names of its contributors may be
//    used to endorse or promote products derived from this software without specific
//    prior written permission.
// 
// THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY
// EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
// MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL
// THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
// SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO,
// PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
// INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT,
// STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF
// THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.


// Install from Pear: pear install XML_RPC2
require_once 'XML/RPC2/Client.php';
// Included in package
require_once 'jsonrpclib.php';

use JsonRPC\Client;

echo "Starting...";

// Everything is wrapped in a try catch with no error handling, as it's ok if seed data is a little out of date
try
{
	// Gandi.net API key
	$apikey = 'PUTYOURAPIKEYHERE'; // Put your API key here
	// The ID of the zone (when editing a zone in Gandi you can see the ID in the URL, it's the number after /admin/domain/zone/
	$zoneid = 000000; // Put the zone ID you're editing here

	// Connect to local Monero instance and call get_connections
        $monerorpc = new Client('http://127.0.0.1:18081/json_rpc');
        $peerconnections = $monerorpc->execute('get_connections');

	// Build up a list of active peer IPs that (seem to) allow inbound connections
	$peerlist = array();
	foreach ($peerconnections['connections'] as $peer)
	{
		// Filter out anything not on port 18080 (we can't have ports in A records, and this also precludes most peers that don't allow incoming connections)
		if ($peer['port'] == 18080)
		{
			$peerlist[] = $peer['ip'];
		}
	}

	// Setup our RPC clients for Gandi: zone records, domain info, zone versioning
	$record_api = XML_RPC2_Client::create('https://rpc.gandi.net/xmlrpc/',
	    array( 'prefix' => 'domain.zone.record.', 'sslverify' => false )
	);

	$zone_api = XML_RPC2_Client::create('https://rpc.gandi.net/xmlrpc/',
	    array( 'prefix' => 'domain.zone.', 'sslverify' => false )
	);

	$version_api = XML_RPC2_Client::create('https://rpc.gandi.net/xmlrpc/',
	    array( 'prefix' => 'domain.zone.version.', 'sslverify' => false )
	);

	// Check what zone version we're currently on (Gandi doesn't let you edit the current zone version)
	$allzones = $zone_api->list($apikey);
	foreach ($allzones as $zone)
	{
		if ($zone['id'] == $zoneid)
		{
			// Store this, as we're going to work in a new zone and then switch
			$oldzoneversion = $zone['version'];
		}
	}

	// This is extremely hacky and assumes you have zone version 3 and 4 already created and ready for everything (except for the seeds.* A records, which are
	// deleted as necessary and then created to match the peerlist). This is just fine for our purposes, but useless for anyone else that doesn't configure this
	// appropriately on Gandi.
	if ($oldzoneversion == 3)
	{
		$zoneversion = 4;
	}
	else
	{
		$zoneversion = 3;
	}

	// Grab the zone records *from the version we're editing* (we don't care about the currently active zone version, we're going to switch away from it)
	$zonerecords = $record_api->list($apikey, $zoneid, $zoneversion);

	// Step through the zone records and delete all of the seed.* records
	foreach ($zonerecords as $record)
	{
		if ($record['name'] == 'seeds')
		{
			$deleterecord = $record_api->delete($apikey, $zoneid, $zoneversion, array('id' => $record['id']));
		}
	}

	// Take our new peer list and add them as A records
	foreach ($peerlist as $peerip)
	{
		// It's a seeds.* A record, value is our peer IP address, and the TTL is 5 minutes
		$addrecord = $record_api->add($apikey, $zoneid, $zoneversion, array(
			'name' => 'seeds',
			'type' => 'A',
			'value' => $peerip,
			'ttl' => 300,
		));
	}

	// Finally, switch zone versions to this new version we've created
	$switchzones = $version_api->set($apikey, $zoneid, $zoneversion);
	echo "Done!\n";
}
catch (Exception $e)
{
	// In the event we encounter an error (eg. Gandi is unavailable, seeds are unavailable) do nothing, we're going to retry in 5 minutes
	echo 'Caught exception: ',  $e->getMessage(), "\n";
}
?>