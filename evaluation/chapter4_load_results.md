# Load, Latency & Scalability Evaluation (Chapter 4 & 5)

## Concurrency & Latency Benchmarks

| Concurrent Students | Total Evaluated Requests | Throughput (Req/sec) | Mean Latency (ms) | p50 Median (ms) | p95 Latency (ms) | p99 Latency (ms) |
|---|---|---|---|---|---|---|
| 50 | 1000 | 95420.5 | 0.009 ms | 0.009 ms | 0.01 ms | 0.014 ms |
| 100 | 2000 | 83559.4 | 0.012 ms | 0.011 ms | 0.016 ms | 0.027 ms |
| 250 | 5000 | 97421.9 | 0.01 ms | 0.009 ms | 0.014 ms | 0.026 ms |
| 500 | 10000 | 110599.9 | 0.009 ms | 0.008 ms | 0.01 ms | 0.014 ms |
| 1000 | 20000 | 108679.2 | 0.009 ms | 0.009 ms | 0.01 ms | 0.014 ms |

## Bandwidth Comparison with Webcam Streaming

- **Webcam Proctoring (1 Mbps Stream):** ~439.5 MB per student/hour
- **SOAR Multi-Indicator Telemetry:** ~0.56 MB per student/hour
- **Network Bandwidth Savings:** **99.87%**
