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

/**
 * The tools a course actually offers: the admin's global selection, narrowed by the course's.
 *
 * - adminTools null/undefined → the admin never chose, so every tool.
 * - courseTools null/undefined → the course inherits the admin's selection.
 * - [] at either level means NO tools, not "all". Treating an empty list as "unset" is what
 *   made "switch every tool off" show every tool (Codeberg #7).
 *
 * The global setting is not optional here: the course view is the only place tools appear,
 * and before this it never read the admin's selection at all.
 */
export function effectiveCourseTools(courseTools, adminTools) {
  const admin = Array.isArray(adminTools) ? adminTools : ALL_TOOL_IDS
  const course = Array.isArray(courseTools) ? courseTools : admin
  return ALL_TOOL_IDS.filter((id) => admin.includes(id) && course.includes(id))
}
