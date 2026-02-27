import {
  ArgumentsHost,
  BadRequestException,
  Catch,
  ExceptionFilter,
  HttpStatus,
} from '@nestjs/common';
import { FastifyReply } from 'fastify';

/**
 * Catches BadRequestException on the connectivity endpoint and reformats
 * class-validator errors into the { ack: { status: "rejected" } } shape.
 */
@Catch(BadRequestException)
export class ConnectivityValidationFilter
  implements ExceptionFilter<BadRequestException>
{
  catch(exception: BadRequestException, host: ArgumentsHost): void {
    const ctx = host.switchToHttp();
    const reply = ctx.getResponse<FastifyReply>();
    const body = exception.getResponse() as Record<string, unknown>;

    // If the exception already carries our custom ack shape, pass it through.
    if (body && typeof body === 'object' && 'ack' in body) {
      reply.status(HttpStatus.BAD_REQUEST).send(body);
      return;
    }

    // Otherwise it's a class-validator error — reformat as rejected ack.
    reply.status(HttpStatus.BAD_REQUEST).send({
      ack: {
        status: 'rejected',
        code: 'invalid_envelope',
        retryable: false,
      },
    });
  }
}
