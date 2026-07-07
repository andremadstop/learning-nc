/**
 * VirtuProf-Duell-Einladungen (Laden, Polling, Annehmen/Ablehnen/Zurückziehen, Popup).
 * Aus VirtuProf.vue extrahiert als Options-API-Mixin (Zero-Behavior-Change).
 * Geteilter Präsentations-/Hilfe-State und vt/$emit leben im Host-Component und werden
 * über die gemergte Instanz aufgelöst. Lifecycle bleibt zentral: mounted ruft
 * startInvitePolling(), beforeUnmount ruft stopInvitePolling() — KEIN eigener Hook hier.
 */
import axios from "@nextcloud/axios"
import { generateUrl } from "@nextcloud/router"
import { mapInviteCard } from "./utils/virtuprof-invites.js"

export default {
	data() {
		return {
			duelInvites: {
				incoming: [],
				outgoing: [],
			},
			inviteError: "",
			inviteNotificationsInitialized: false,
			notifiedInviteIds: [],
			invitePollingInterval: null,
			activeInviteFilter: "incoming",
		}
	},
	methods: {
    async openInviteList(filter = 'incoming') {
      this.resetTicketFeedback()
      this.helpView = 'invite-list'
      this.activeFaqId = null
      this.activeFaqCategoryId = null
      this.activeInviteFilter = filter
      this.visible = true
      this.isMinimized = false
      this.currentAnimation = 'wave'
      this.inviteError = ''
      await this.refreshDuelInvites(false)
    },
    buildInviteListStep() {
      return {
        kind: 'invite-list',
        title: this.vt('Duel invites'),
        text: this.inviteError || this.vt('Open invites stay here until they are declined, canceled or the duel has been played.'),
        inviteGroups: this.buildInviteGroups(),
        actions: [
          { label: this.vt('Incoming duel invites ({n})', { n: this.duelInvites.incoming.length }), type: 'open-invite-list', filter: 'incoming' },
          { label: this.vt('Outgoing duel invites ({n})', { n: this.duelInvites.outgoing.length }), type: 'open-invite-list', filter: 'outgoing' },
          { label: this.vt('Refresh'), type: 'refresh-invites' },
          { label: this.vt('Back'), type: 'open-help-home' },
        ],
      }
    },
    buildInviteGroups() {
      const groups = []
      const includeIncoming = this.activeInviteFilter === 'incoming' || this.activeInviteFilter === 'all'
      const includeOutgoing = this.activeInviteFilter === 'outgoing' || this.activeInviteFilter === 'all'

      if (includeIncoming) {
        groups.push({
          id: 'incoming',
          title: this.vt('Incoming duel invites'),
          invites: this.duelInvites.incoming.map(invite => mapInviteCard(invite, this.vt)),
        })
      }
      if (includeOutgoing) {
        groups.push({
          id: 'outgoing',
          title: this.vt('Outgoing duel invites'),
          invites: this.duelInvites.outgoing.map(invite => mapInviteCard(invite, this.vt)),
        })
      }
      return groups.filter(group => group.invites.length > 0)
    },
    handleInviteRefreshRequest() {
      this.refreshDuelInvites(false)
    },
    startInvitePolling() {
      if (this.invitePollingInterval) {
        return
      }
      this.invitePollingInterval = setInterval(() => {
        this.refreshDuelInvites(true)
      }, 15000)
    },
    stopInvitePolling() {
      if (this.invitePollingInterval) {
        clearInterval(this.invitePollingInterval)
        this.invitePollingInterval = null
      }
    },
    async refreshDuelInvites(triggerPopup = false) {
      try {
        const response = await axios.get(generateUrl('/apps/learning/api/duel-invites'))
        const incoming = Array.isArray(response.data?.incoming) ? response.data.incoming : []
        const outgoing = Array.isArray(response.data?.outgoing) ? response.data.outgoing : []
        this.duelInvites = { incoming, outgoing }
        this.inviteError = ''

        const openIncomingIds = incoming
          .filter(invite => invite.status === 'open')
          .map(invite => invite.id)

        if (!this.inviteNotificationsInitialized) {
          this.notifiedInviteIds = [...openIncomingIds]
          this.inviteNotificationsInitialized = true
          return
        }

        const newInviteIds = openIncomingIds.filter(id => !this.notifiedInviteIds.includes(id))
        if (newInviteIds.length > 0) {
          this.notifiedInviteIds = [...new Set([...this.notifiedInviteIds, ...newInviteIds])]
          if (triggerPopup && !this.isHelpOpen && (!this.visible || this.isMinimized)) {
            await this.openInviteList('incoming')
          }
        }
      } catch (e) {
        this.duelInvites = { incoming: [], outgoing: [] }
        this.inviteError = this.vt('Failed to load duel invites')
      }
    },
    async acceptInvite(inviteId) {
      try {
        const response = await axios.post(generateUrl('/apps/learning/api/duel-invites/' + inviteId + '/accept'))
        await this.refreshDuelInvites(false)
        const invite = response.data?.invite || response.data
        this.openDuel(invite.course_id, invite.duel_code)
      } catch (e) {
        this.inviteError = e?.response?.data?.error || this.vt('Failed to accept duel invite')
      }
    },
    async declineInvite(inviteId) {
      try {
        await axios.post(generateUrl('/apps/learning/api/duel-invites/' + inviteId + '/decline'))
        await this.refreshDuelInvites(false)
      } catch (e) {
        this.inviteError = e?.response?.data?.error || this.vt('Failed to decline duel invite')
      }
    },
    async cancelInvite(inviteId) {
      try {
        await axios.post(generateUrl('/apps/learning/api/duel-invites/' + inviteId + '/cancel'))
        await this.refreshDuelInvites(false)
      } catch (e) {
        this.inviteError = e?.response?.data?.error || this.vt('Failed to cancel duel invite')
      }
    },
    openDuel(courseId, duelCode) {
      this.closeHelp()
      this.$emit('open-duel', { courseId, duelCode })
    },
	},
}
