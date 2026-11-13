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

/**
 * Whether the course shows simulator tools at all (GitHub #35): mode_config.tools is the
 * course's master switch. Only an explicit false switches them off, so a course saved before
 * the switch existed keeps its tools.
 *
 * @param {object|null|undefined} course - the course (snake_case fields)
 * @return {boolean}
 */
export function courseToolsSwitchedOn(course) {
  return course?.mode_config?.tools !== false
}

/**
 * The tools a course offers to the viewer: none while the master switch is off, otherwise
 * effectiveCourseTools(). Every reader (routing, the Werkzeuge tab, the tool hints in the
 * learning space, the global tools view with a course selected) goes through here, so the
 * switch cannot be honoured in one place and missed in another.
 *
 * @param {object|null|undefined} course - the course (snake_case fields)
 * @param {string[]|null|undefined} adminTools - the admin's global selection
 * @return {string[]}
 */
export function courseToolIds(course, adminTools) {
  if (!courseToolsSwitchedOn(course)) {
    return []
  }
  return effectiveCourseTools(course?.enabled_tools, adminTools)
}
