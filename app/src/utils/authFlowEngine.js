const METHOD_OVERRIDES = Object.freeze({
	'eap-tls': {
		label: 'EAP-TLS',
		challenge: 'Client presents certificate and proves private key possession.',
		certificates: 'Client and server certificates required.',
	},
	peap: {
		label: 'PEAP',
		challenge: 'Server certificate builds the tunnel, user answers with MSCHAPv2.',
		certificates: 'Server certificate required, no client certificate.',
	},
	'eap-fast': {
		label: 'EAP-FAST',
		challenge: 'PAC bootstraps the tunnel before user credentials are validated.',
		certificates: 'PAC or optional server certificate.',
	},
})

export function getMethodComparison() {
	return [
		{ method: 'EAP-TLS', clientCertificate: 'Ja', serverCertificate: 'Ja', tunnel: 'TLS beidseitig', security: 'Hoechste', effort: 'Hoch (PKI)' },
		{ method: 'PEAP', clientCertificate: 'Nein', serverCertificate: 'Ja', tunnel: 'TLS (Server) + MSCHAPv2', security: 'Hoch', effort: 'Mittel' },
		{ method: 'EAP-FAST', clientCertificate: 'Nein', serverCertificate: 'Optional', tunnel: 'PAC + TLS', security: 'Mittel-Hoch', effort: 'Niedrig' },
	]
}

export function getMethodDetails(method) {
	const normalized = String(method || 'eap-tls').trim().toLowerCase()
	return METHOD_OVERRIDES[normalized] || METHOD_OVERRIDES['eap-tls']
}

export function buildAuthSequence(method = 'eap-tls', outcome = 'accept') {
	const details = getMethodDetails(method)
	return [
		{ id: 'connect', actorFrom: 'Client', actorTo: 'Switch', label: 'Client connects to the switch port', detail: 'Physical link comes up and 802.1X starts.' },
		{ id: 'block', actorFrom: 'Switch', actorTo: 'Client', label: 'Port stays blocked for regular traffic', detail: 'Only EAPOL is allowed until authentication succeeds.' },
		{ id: 'identity-request', actorFrom: 'Switch', actorTo: 'Client', label: 'EAP-Request Identity', detail: 'Authenticator asks who is on the port.' },
		{ id: 'identity-response', actorFrom: 'Client', actorTo: 'Switch', label: 'EAP-Response Identity', detail: 'Supplicant sends its claimed identity.' },
		{ id: 'radius-request', actorFrom: 'Switch', actorTo: 'RADIUS', label: 'RADIUS Access-Request', detail: 'Switch forwards the identity to the RADIUS server.' },
		{ id: 'challenge', actorFrom: 'RADIUS', actorTo: 'Client', label: `${details.label} challenge`, detail: details.challenge },
		{ id: 'credential-response', actorFrom: 'Client', actorTo: 'RADIUS', label: 'Credential / certificate response', detail: details.certificates },
		{ id: outcome === 'accept' ? 'accept' : 'reject', actorFrom: 'RADIUS', actorTo: 'Switch', label: outcome === 'accept' ? 'Access-Accept' : 'Access-Reject', detail: outcome === 'accept' ? 'Switch opens the port.' : 'Switch keeps the port blocked.' },
	]
}

export function validateSequence(sequenceIds, method = 'eap-tls', outcome = 'accept') {
	const expectedIds = buildAuthSequence(method, outcome).map((step) => step.id)
	const submitted = sequenceIds || []
	const steps = expectedIds.map((id, index) => ({
		id,
		expectedIndex: index,
		submittedIndex: submitted.indexOf(id),
		correct: submitted[index] === id,
	}))

	return {
		isCorrect: steps.every((step) => step.correct),
		correctCount: steps.filter((step) => step.correct).length,
		steps,
	}
}

export function buildShuffledSequence(method = 'eap-tls', outcome = 'accept') {
	const sequence = buildAuthSequence(method, outcome)
	return [sequence[2], sequence[0], sequence[4], sequence[1], sequence[6], sequence[3], sequence[7], sequence[5]]
}
