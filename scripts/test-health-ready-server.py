#!/usr/bin/env python3
"""Loopback-only HTTP fixture for isolated restore health-check tests."""

from http.server import BaseHTTPRequestHandler, HTTPServer


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path == "/health/ready":
            status, body = 200, b'{"status":"ready","checks":{"database":"ok"}}'
        elif self.path == "/bad-body":
            status, body = 200, b'{"status":"degraded","checks":{"database":"down"}}'
        else:
            status, body = 503, b'{"status":"unavailable","checks":{"database":"down"}}'
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, _format, *_args):
        pass


server = HTTPServer(("127.0.0.1", 0), Handler)
print(server.server_address[1], flush=True)
server.serve_forever()
