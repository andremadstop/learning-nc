/**
 * VirtuProf Help / FAQ / Support-Tickets (Navigation + Step-Builder + Ticket-CRUD).
 * Aus VirtuProf.vue extrahiert als Options-API-Mixin (Zero-Behavior-Change).
 *
 * Deckt: Help-Home/Context, FAQ-Liste/Kategorie/Antwort, Ticket-Formular/Liste inkl.
 * Laden und Absenden. Die zugehörigen Step-Builder leben hier; der zentrale
 * buildHelpStep-Dispatcher bleibt im Host-Component und ruft sie über die gemergte
 * Instanz auf.
 *
 * Bewusst NICHT hier (bleiben im Host-Component, via Merge aufgelöst):
 * - helpView (zentraler View-State, von mehreren Mixins + Lifecycle gesetzt),
 * - das Guide-System (handleGuide/persistGuideVisit/buildGuideStep/guideStep/
 *   visitedGuideKeys/repeatGuideKeys) — an Trigger-Engine + Persistenz gekoppelt,
 * - handleReportError (Cross-Cutting Chat), applyReaction, vt, processNext,
 *   isBasicMode/isHelpOpen/hasActiveQuestionContext/currentBubbleStep (Kern-Computeds).
 * closeHelp delegiert bei aktivem Onboarding an declineOnboarding (Onboarding-Mixin).
 * Kein eigener Lifecycle-Hook.
 */
import axios from "@nextcloud/axios"
import { generateUrl } from "@nextcloud/router"
import { FAQ_CATEGORIES, FAQS } from "./utils/virtuprof-scripts.js"
import { recommendedFaqCategoryId, contextHelpEntry } from "./utils/virtuprof-context-help.js"

export default {
	data() {
		return {
			activeFaqId: null,
			activeFaqCategoryId: null,
			ticketSubject: '',
			ticketDraft: '',
			ticketCategory: 'technical',
			ticketSending: false,
			ticketError: '',
			ticketSuccess: '',
			myTickets: [],
		}
	},
	computed: {
		orderedFaqIds() {
			const ids = Object.keys(FAQS).filter(id => {
				if (!this.activeFaqCategoryId) {
					return true
				}
				return FAQS[id].category === this.activeFaqCategoryId
			})
			return ids.sort((left, right) => this.vt(FAQS[left].label).localeCompare(this.vt(FAQS[right].label)))
		},
		orderedFaqCategoryIds() {
			const ids = Object.keys(FAQ_CATEGORIES)
			const recommendedId = recommendedFaqCategoryId(this.currentContext?.area)
			if (!recommendedId || !FAQ_CATEGORIES[recommendedId]) {
				return ids
			}
			return [recommendedId, ...ids.filter(id => id !== recommendedId)]
		},
		hasCourseContext() {
			return !!(this.currentContext && this.currentContext.courseTitle)
		},
		categoryHint() {
			if (this.ticketCategory === 'course_content') {
				return this.vt('Your question will be sent to the course instructor.')
			}
			return this.vt('Your question will be sent to the admin.')
		},
	},
	methods: {
		openHelpHome() {
			this.resetTicketFeedback()
			this.helpView = 'home'
			this.activeFaqId = null
			this.activeFaqCategoryId = null
			this.visible = true
			this.isMinimized = false
			this.currentAnimation = 'wave'
		},
		openContextHelp() {
			this.resetTicketFeedback()
			this.helpView = 'context'
			this.activeFaqId = null
			this.activeFaqCategoryId = null
			this.visible = true
			this.isMinimized = false
			this.currentAnimation = 'talk'
		},
		openFaqList() {
			this.resetTicketFeedback()
			this.helpView = 'faq-list'
			this.activeFaqId = null
			this.activeFaqCategoryId = null
			this.visible = true
			this.isMinimized = false
			this.currentAnimation = 'talk'
		},
		openFaqCategory(categoryId) {
			if (!FAQ_CATEGORIES[categoryId]) {
				return
			}
			this.resetTicketFeedback()
			this.helpView = 'faq-category'
			this.activeFaqId = null
			this.activeFaqCategoryId = categoryId
			this.visible = true
			this.isMinimized = false
			this.currentAnimation = 'talk'
		},
		openFaq(faqId) {
			if (!FAQS[faqId]) {
				return
			}
			this.resetTicketFeedback()
			this.helpView = 'faq-answer'
			this.activeFaqId = faqId
			this.activeFaqCategoryId = FAQS[faqId].category || null
			this.visible = true
			this.isMinimized = false
			this.currentAnimation = 'talk'
		},
		openTicketForm() {
			this.helpView = 'ticket-form'
			this.visible = true
			this.isMinimized = false
			this.currentAnimation = 'talk'
			this.ticketError = ''
			this.ticketSuccess = ''
			if (!this.ticketSubject) {
				this.ticketSubject = this.defaultTicketSubject()
			}
			this.ticketCategory = this.hasCourseContext ? 'course_content' : 'technical'
		},
		async openTicketList() {
			this.helpView = 'ticket-list'
			this.visible = true
			this.isMinimized = false
			this.currentAnimation = 'talk'
			this.ticketError = ''
			this.ticketSuccess = ''
			await this.loadMyTickets()
		},
		closeHelp() {
			if (this.telosOnboardingActive) {
				this.declineOnboarding()
				return
			}
			this.helpView = null
			this.guideStep = null
			this.activeFaqId = null
			this.activeFaqCategoryId = null
			this.resetTicketFeedback()
			if (!this.currentScriptId) {
				this.visible = false
				this.currentAnimation = 'idle'
			}
			if (this.queue.length > 0 && !this.processing) {
				this.processNext()
			}
		},
		buildHelpHomeStep() {
			const incomingCount = this.duelInvites.incoming.length
			const outgoingCount = this.duelInvites.outgoing.length
			const inviteActions = []
			if (incomingCount > 0) {
				inviteActions.push({
					label: this.vt('Incoming duel invites ({n})', { n: incomingCount }),
					type: 'open-invite-list',
					filter: 'incoming',
				})
			}
			if (outgoingCount > 0) {
				inviteActions.push({
					label: this.vt('Outgoing duel invites ({n})', { n: outgoingCount }),
					type: 'open-invite-list',
					filter: 'outgoing',
				})
			}
			const profileAction = this.isBasicMode
				? [{ label: this.vt('Set up learning profile'), type: 'start-journey' }]
				: []
			return {
				title: this.vt('VirtuProf'),
				text: this.isBasicMode
					? this.vt('I can help you, but without a learning profile my tips are more generic. Set up your profile for personalized support.')
					: this.vt('I stay in the corner now. Open me any time for short explanations or quick FAQs.'),
				actions: [
					...profileAction,
					...inviteActions,
					{ label: this.vt('What can I do here?'), type: 'open-context-help' },
					{ label: this.vt('Top questions for this area'), type: 'open-faq-category', categoryId: recommendedFaqCategoryId(this.currentContext?.area) },
					{ label: this.vt('Browse all topics'), type: 'open-faq-list' },
					{ label: this.vt('Send a question'), type: 'open-ticket-form' },
					{ label: this.vt('My requests'), type: 'open-ticket-list' },
					{ label: this.vt('Close'), type: 'close-help' },
				],
			}
		},
		buildFaqListStep() {
			return {
				title: this.vt('FAQs'),
				text: this.vt('Choose a topic first. Then I will show the most common questions inside it.'),
				actionLayout: 'stacked',
				actions: [
					...this.orderedFaqCategoryIds.map(categoryId => ({
						label: this.vt(FAQ_CATEGORIES[categoryId].label),
						type: 'open-faq-category',
						categoryId,
					})),
					{ label: this.vt('Back'), type: 'open-help-home' },
				],
			}
		},
		buildFaqCategoryStep() {
			const category = FAQ_CATEGORIES[this.activeFaqCategoryId]
			if (!category) {
				return this.buildFaqListStep()
			}
			return {
				title: this.vt(category.title),
				text: this.vt(category.description),
				actionLayout: 'stacked',
				actions: [
					...this.orderedFaqIds.map(faqId => ({
						label: this.vt(FAQS[faqId].label),
						type: 'open-faq',
						faqId,
					})),
					{ label: this.vt('All topics'), type: 'open-faq-list' },
					{ label: this.vt('Back'), type: 'open-help-home' },
				],
			}
		},
		buildFaqAnswerStep() {
			const faq = FAQS[this.activeFaqId]
			if (!faq) {
				return this.buildFaqListStep()
			}
			return {
				title: this.vt(faq.title),
				text: this.vt(faq.text),
				actions: [
					{ label: this.vt('Ask about this'), type: 'open-ticket-form' },
					{ label: this.vt('More in this topic'), type: 'open-faq-category', categoryId: faq.category },
					{ label: this.vt('All topics'), type: 'open-faq-list' },
					{ label: this.vt('Back'), type: 'open-help-home' },
				],
			}
		},
		buildContextStep() {
			const entry = contextHelpEntry(this.currentContext?.area, this.currentContext?.poolName, this.currentContext?.courseTitle, this.vt)
			return {
				title: entry.title,
				text: entry.text,
				actions: [
					{ label: this.vt('Top questions for this area'), type: 'open-faq-category', categoryId: recommendedFaqCategoryId(this.currentContext?.area) },
					{ label: this.vt('Browse all topics'), type: 'open-faq-list' },
					{ label: this.vt('Send a question'), type: 'open-ticket-form' },
					{ label: this.vt('Back'), type: 'open-help-home' },
				],
			}
		},
		buildTicketFormStep() {
			return {
				kind: 'ticket-form',
				title: this.vt('Send a question'),
				text: this.vt('Your message will be stored as a support ticket with context, so an admin can answer it manually later.'),
				placeholder: this.vt('Describe your question or problem...'),
				ticketCategory: this.ticketCategory,
				hasCourseContext: this.hasCourseContext,
				categoryHint: this.categoryHint,
				actions: [
					{ label: this.ticketSending ? this.vt('Sending...') : this.vt('Send ticket'), type: 'submit-ticket' },
					{ label: this.vt('My requests'), type: 'open-ticket-list' },
					{ label: this.vt('Back'), type: 'open-help-home' },
				],
			}
		},
		buildTicketListStep() {
			return {
				kind: 'ticket-list',
				title: this.vt('My support tickets'),
				text: this.vt('Open tickets wait for an admin reply. Answered tickets keep the latest response here.'),
				actions: [
					{ label: this.vt('New ticket'), type: 'open-ticket-form' },
					{ label: this.vt('Refresh'), type: 'open-ticket-list' },
					{ label: this.vt('Back'), type: 'open-help-home' },
				],
			}
		},
		defaultTicketSubject() {
			const faq = this.activeFaqId && FAQS[this.activeFaqId] ? FAQS[this.activeFaqId] : null
			if (faq?.title) {
				return this.vt(faq.title)
			}
			const parts = []
			if (this.currentContext?.courseTitle) {
				parts.push(this.currentContext.courseTitle)
			}
			if (this.currentContext?.poolName) {
				parts.push(this.currentContext.poolName)
			}
			const area = String(this.currentContext?.area || '').trim()
			if (area) {
				parts.push(area)
			}
			return parts.join(' | ').slice(0, 255)
		},
		resetTicketFeedback() {
			this.ticketError = ''
			this.ticketSuccess = ''
		},
		async loadMyTickets() {
			try {
				const response = await axios.get(generateUrl('/apps/learning/api/support-tickets'), {
					params: { limit: 20 },
				})
				this.myTickets = Array.isArray(response.data?.tickets) ? response.data.tickets : []
			} catch (e) {
				this.myTickets = []
				this.ticketError = this.vt('Failed to load support tickets')
			}
		},
		async submitTicket() {
			if (this.ticketSending) {
				return
			}
			this.ticketSending = true
			this.ticketError = ''
			this.ticketSuccess = ''
			try {
				await axios.post(generateUrl('/apps/learning/api/support-tickets'), {
					subject: this.ticketSubject || this.defaultTicketSubject(),
					message: this.ticketDraft,
					category: this.ticketCategory,
					context: {
						...this.currentContext,
						faqId: this.activeFaqId,
						faqCategoryId: this.activeFaqCategoryId,
						helpView: this.helpView,
						title: this.currentBubbleStep?.title || '',
					},
				})
				this.ticketDraft = ''
				this.ticketSubject = this.defaultTicketSubject()
				this.ticketCategory = this.hasCourseContext ? 'course_content' : 'technical'
				this.ticketSuccess = this.vt('Support ticket sent')
				await this.loadMyTickets()
			} catch (e) {
				this.ticketError = e?.response?.data?.error || this.vt('Failed to send support ticket')
			} finally {
				this.ticketSending = false
			}
		},
	},
}
