export type AckStatus = 'accepted' | 'rejected';
export type AckDisposition = 'duplicate';

export interface AckDetail {
  status: AckStatus;
  disposition?: AckDisposition;
  code?: string;
  retryable?: boolean;
}

export interface AckResponse {
  ack: AckDetail;
}

export interface ErrorResponse {
  error: {
    code: string;
    retryable: boolean;
    retry_after_seconds?: number;
  };
}
