'use client';

import { Component, type ReactNode } from 'react';
import { Alert, Button } from '@pixel-series/design-system-neumorphic';

interface ErrorBoundaryProps {
  children: ReactNode;
  fallbackTitle?: string;
  fallbackMessage?: string;
}

interface ErrorBoundaryState {
  hasError: boolean;
  error: Error | null;
}

/**
 * Generic error boundary for section-level error catching.
 * Use this to wrap individual sections within a page.
 * Route-level errors are handled by Next.js error.tsx.
 */
export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  constructor(props: ErrorBoundaryProps) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error: Error): ErrorBoundaryState {
    return { hasError: true, error };
  }

  render() {
    if (this.state.hasError) {
      return (
        <Alert variant="error" title={this.props.fallbackTitle ?? 'Error'}>
          <div className="space-y-3">
            <p>{this.props.fallbackMessage ?? 'Something went wrong in this section.'}</p>
            {process.env.NODE_ENV === 'development' && this.state.error && (
              <p className="text-xs font-mono opacity-75">{this.state.error.message}</p>
            )}
            <Button
              size="sm"
              variant="secondary"
              onClick={() => this.setState({ hasError: false, error: null })}
            >
              Retry
            </Button>
          </div>
        </Alert>
      );
    }

    return this.props.children;
  }
}
