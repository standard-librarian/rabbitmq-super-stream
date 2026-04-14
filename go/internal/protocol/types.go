package protocol

type PublishRequest struct {
	ProtocolVersion int            `json:"protocol_version"`
	RequestID       string         `json:"request_id"`
	Message         PublishMessage `json:"message"`
	Options         PublishOptions `json:"options"`
}

type PublishMessage struct {
	BodyBase64    string         `json:"body_base64"`
	RoutingKey    string         `json:"routing_key"`
	MessageID     string         `json:"message_id,omitempty"`
	CorrelationID string         `json:"correlation_id,omitempty"`
	ContentType   string         `json:"content_type,omitempty"`
	Headers       map[string]any `json:"headers,omitempty"`
}

type PublishOptions struct {
	WaitForConfirm   bool `json:"wait_for_confirm"`
	ConfirmTimeoutMs int  `json:"confirm_timeout_ms"`
}

type PublishResult struct {
	MessageID   string `json:"message_id,omitempty"`
	Confirmed   bool   `json:"confirmed"`
	HelperPID   int    `json:"helper_pid"`
	Transport   string `json:"transport"`
	AcceptedAt  string `json:"accepted_at"`
	ConfirmedAt string `json:"confirmed_at,omitempty"`
}

type Envelope struct {
	ProtocolVersion int         `json:"protocol_version"`
	RequestID       string      `json:"request_id,omitempty"`
	Status          string      `json:"status"`
	Result          any         `json:"result,omitempty"`
	Error           *ErrorValue `json:"error,omitempty"`
}

type ErrorValue struct {
	Code      string         `json:"code"`
	Message   string         `json:"message"`
	Retryable bool           `json:"retryable"`
	Details   map[string]any `json:"details,omitempty"`
}

type HealthResult struct {
	HelperPID       int    `json:"helper_pid"`
	Transport       string `json:"transport"`
	SuperStream     string `json:"super_stream"`
	Ready           bool   `json:"ready"`
	ProtocolVersion int    `json:"protocol_version"`
}
