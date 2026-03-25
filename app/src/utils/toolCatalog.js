export const TOOL_CATALOG = Object.freeze([
  { id: 'subnet', labelKey: 'Subnetzrechner', shortLabelKey: 'Subnetz', icon: '🔢' },
  { id: 'dns', labelKey: 'DNS-Resolver', shortLabelKey: 'DNS', icon: '🌐' },
  { id: 'firewall', labelKey: 'Firewall / ACL Builder', shortLabelKey: 'Firewall', icon: '🛡️' },
  { id: 'portscan', labelKey: 'Port-Scanner', shortLabelKey: 'Ports', icon: '🔍' },
  { id: 'routing', labelKey: 'Routing-Tabelle', shortLabelKey: 'Routing', icon: '🗺️' },
  { id: 'nat', labelKey: 'NAT-Tabelle', shortLabelKey: 'NAT', icon: '🔄' },
  { id: 'wireshark', labelKey: 'Wireshark-Lite', shortLabelKey: 'Wireshark', icon: '📡' },
  { id: 'authflow', labelKey: '802.1X Auth-Flow', shortLabelKey: '802.1X', icon: '🔐' },
])

export const ALL_TOOL_IDS = Object.freeze(TOOL_CATALOG.map((tool) => tool.id))
