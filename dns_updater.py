#!/usr/bin/which python3
import requests
import json
import sys
import time
import socket


class CloudFlareDNS:
    real_zone, zone_id = [None, None]

    def __init__(self, login, password, zone, debug=False):
        import CloudFlare
        self.cf = CloudFlare.CloudFlare(email=login, token=password, debug=debug)
        self.zone = zone
        self.debug = debug
        self.get_zone_id()

    def get_current_entries(self, subdomain):
        dns_records = self.cf.zones.dns_records.get(self.zone_id)
        record_list = {}
        for dns_record in dns_records:
            if self.debug:
                print(dns_record)
            if dns_record['name'] == "{}.{}".format(subdomain, self.zone):
                record_list[dns_record['id']] = dns_record['content']
        return record_list

    def get_zone_id(self):
        zones = self.cf.zones.get(params={'name': self.zone})
        if self.debug:
            print("Zones response: {}".format(zones))
        if len(zones) == 0:
            raise ValueError("Invalid zone name")
        self.real_zone = zones[0]
        self.zone_id = self.real_zone['id']
        if self.debug:
            print("Zone ID for {} is {}".format(self.zone, self.zone_id))

    def update_zones(self, entries, subdomain):
        if self.debug:
            print("Updating DNS with: {}".format(entries))
        for k, v in self.get_current_entries(subdomain).items():
            if k not in entries['current'].keys():
                print("Removing DNS entry: {} for IP {}".format(k, v))
                self.cf.zones.dns_records.delete(self.zone_id, k)
        for new_ip in entries['new']:
            print("Adding DNS entry for IP {}".format(new_ip))
            self.cf.zones.dns_records.post(self.zone_id,
                                           data={'type': 'A', 'name': "{}.{}".format(subdomain, self.zone),
                                                 'content': new_ip, 'ttl': 300, 'proxied': False})


def test_p2p_connectivity(ip, port):
    s = socket.socket()
    return_state = False
    try:
        s.settimeout(1)
        s.connect((ip, int(port)))
        return_state = True
    except Exception as e:
        print("Unable to connect to {}:{} exception is: {}".format(ip, port, e))
    finally:
        s.close()
    return return_state


def process_update(zone_data, debug=False):
    print("Starting run on: {}.{}".format(zone_data['subdomain'], zone_data['domain']))
    if zone_data['provider'] == 'cloudflare':
        try:
            dns_provider = CloudFlareDNS(zone_data['login'], zone_data['password'], zone_data['domain'], debug)
        except:
            raise
    else:
        print("{} is an invalid DNS provider!  Aborting!".format(zone_data['provider']))
        sys.exit(1)
    current_entries = dns_provider.get_current_entries(zone_data['subdomain'])
    entry_list = {'current': {}, 'new': []}
    current_ips = []
    if len(current_entries) > 0:
        for ip_id, ip in current_entries.items():
            if test_p2p_connectivity(ip, zone_data['required_port']):
                entry_list['current'][ip_id] = ip
                current_ips.append(ip)
    daemon_data = requests.post("http://{}:{}/json_rpc".format(zone_data['rpc_host'], zone_data['rpc_port']),
                                data=json.dumps({"jsonrpc": "2.0", "id": "0", "method": "get_connections"}),
                                headers={"Content-Type": "application/json"})
    if daemon_data.status_code == 200:
        if debug:
            print(daemon_data.json())
        for connection in daemon_data.json()['result']['connections']:
            if connection['ip'] in current_ips:
                print("Ignoring: {}:{} as the IP is in the current list".format(connection['ip'],
                                                                                connection['port']))
                continue
            if connection['state'] != "state_normal":
                print("Ignoring: {}:{} as the state is: {}".format(connection['ip'],
                                                                   connection['port'],
                                                                   connection['state']))
                continue
            if connection['port'] != zone_data['required_port']:
                print("Ignoring: {}:{} as the port is not: {}".format(connection['ip'],
                                                                      connection['port'],
                                                                      zone_data['required_port']))
                continue
            if not test_p2p_connectivity(connection['ip'], zone_data['required_port']):
                print("Ignoring: {}:{} unable to connect to: {}".format(connection['ip'],
                                                                        connection['port'],
                                                                        zone_data['required_port']))
            entry_list['new'].append(connection['ip'])
    dns_provider.update_zones(entry_list, zone_data['subdomain'])
    print("Run complete on: {}.{} new entry count: {}".format(zone_data['subdomain'], zone_data['domain'],
                                                              len(entry_list['new']) + len(entry_list['current'])))


def main():
    with open('config.json', 'r') as f:
        config = json.load(f)
        if len(sys.argv) == 1:
            print("Need to select a zone, or all!")
            sys.exit(1)
        if len(sys.argv) == 2 and sys.argv[1] not in config['zones'] and sys.argv[1] != 'all':
            print("{} not configured for DNS update!  Aborting!".format(sys.argv[1]))
            sys.exit(1)
    while True:
        try:
            if sys.argv[1] == 'all':
                for k, v in config['zones'].items():
                    process_update(v, config['debug'])
            else:
                process_update(config['zones'][sys.argv[1]], config['debug'])
        except Exception as e:
            print("Hit exception: {}".format(e))
        if config['loop'] is False:
            break
        time.sleep(config['delay'])


main()
