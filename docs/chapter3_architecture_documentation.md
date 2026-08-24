# SOAR Platform Architecture & Technical Design (Chapter 3)
============================================================

## 1. High-Level Architecture Overview

The **SOAR (Security Orchestration, Automation, and Response)** platform is designed specifically for academic exam integrity without compromising student privacy (no video streaming, no microphone recording, and no invasive filesystem scanning).

```mermaid
graph TB
    subgraph "Moodle LMS Client Layer"
        MoodleCore[Moodle LMS Platform]
        MoodlePlugin[Exam Monitor Access Rule Plugin<br/>`quizaccess_exammonitor`]
        StudentBrowser[Student Browser & Quiz DOM]
        MoodleCore --> MoodlePlugin
        MoodlePlugin --> StudentBrowser
    end

    subgraph "Ingestion & Security Gateway"
        IngestAPI[Telemetry Ingest API<br/>`POST /api/telemetry`]
        SyncAPI[Lifecycle Sync API<br/>`POST /api/sync`]
        SecurityGuard[Auth, RBAC & Multi-Tenant Guard<br/>`account_id` Scoping]
    end

    subgraph "SOAR 4-Pillar Analytical Engines"
        EngineB[1. Behavioral & Cognitive Engine<br/>`CognitiveAnalyzer.php`<br/>Hick's Law & Focus Time]
        EngineA[2. GenAI Content Detector<br/>`AIDetector.php`<br/>Failover Multi-Provider API]
        EngineS[3. Similarity Matrix Engine<br/>`SimilarityEngine.php`<br/>Word Trigram Cosine]
        EngineN[4. Network & Session Engine<br/>`NetworkAnalyzer.php`<br/>IP & Concurrent Tracking]
    end

    subgraph "Decision & Risk Correlation Core"
        RiskCore[SOAR Risk Engine<br/>`RiskEngine.php`<br/>Equation 3.16 Weighted Sum]
        NISTClassifier[NIST SP 800-30 Classifier<br/>Table 3.1 Risk Levels]
        DynamicPresets[Dynamic Weight Customizer<br/>MCQ / Essay / Balanced Presets]
        RiskCore --> NISTClassifier
        DynamicPresets --> RiskCore
    end

    subgraph "Automated Response & Decision Support Layer"
        ResponseEngine[SOAR Response Engine<br/>`ResponseEngine.php`]
        TeacherActions[Teacher Action System<br/>`TeacherActionController.php`<br/>Warning / Lock / Time Deduction]
    end

    subgraph "Teacher & Admin Web Portal"
        ReactSPA[React Dashboard & Analytics UI]
        LiveMonitor[Real-time Live Monitor]
        SimMatrixView[Similarity Matrix Viewer]
        ReactSPA --> LiveMonitor
        ReactSPA --> SimMatrixView
    end

    %% Data Flow Connections
    StudentBrowser -- "Encrypted Event Batch (Every 5s)" --> IngestAPI
    MoodlePlugin -- "Lifecycle Sync (Courses/Exams/Users)" --> SyncAPI
    IngestAPI --> SecurityGuard
    SyncAPI --> SecurityGuard

    SecurityGuard --> EngineB
    SecurityGuard --> EngineA
    SecurityGuard --> EngineS
    SecurityGuard --> EngineN

    EngineB --> RiskCore
    EngineA --> RiskCore
    EngineS --> RiskCore
    EngineN --> RiskCore

    RiskCore --> ResponseEngine
    RiskCore --> ReactSPA
    ResponseEngine --> TeacherActions
    TeacherActions -- "Real-time Action Delivery" --> StudentBrowser
```

---

## 2. Telemetry Ingestion & Correlation Sequence Diagram

```mermaid
sequenceDiagram
    autonumber
    actor Student as Student Browser
    participant Plugin as Moodle Access Rule
    participant API as Telemetry API (/api/telemetry)
    participant Core as RiskEngine (Eq 3.16)
    participant DB as MySQL Database
    actor Teacher as Teacher Live Dashboard

    Student->>Plugin: Interacts with Exam (Focus, Typing, Paste)
    Note over Plugin: Collects & buffers events<br/>(Filters transient blurs < 3s)
    Plugin->>API: HTTP POST Telemetry Batch (JSON < 1KB)
    API->>API: Authenticate & Validate Account Secret
    API->>DB: Store raw events in `events`
    API->>Core: Trigger Real-time Risk Calculation
    Core->>Core: Compute B_i (Eq 3.2-3.6), A_i, S_i, N_i
    Core->>Core: Apply Eq 3.16 Weighted Normalization
    Core->>DB: Update `session_summaries` (risk_score, risk_level)
    DB-->>Teacher: Live Polling / WebSocket Stream Update
    alt Risk Score >= 21% (NIST Alert Threshold)
        Core->>Teacher: Highlight Student in Red/Orange
        Teacher->>API: Send Warning Message / Lock Session
        API->>Student: Deliver Action Modal on Exam Screen
    end
```

---

## 3. Data Flow Diagram (DFD - Level 1)

```mermaid
graph LR
    subgraph Entities
        E1[Student Browser]
        E2[Moodle LMS Server]
        E3[Teacher Dashboard]
        E4[RapidAPI AI Detection]
    end

    subgraph Processes
        P1["1.0 Ingest & Throttle Telemetry"]
        P2["2.0 Normalize Behavioral & Cognitive Metrics"]
        P3["3.0 Detect AI Content & Trigram Similarity"]
        P4["4.0 Correlate NIST Risk Score (Eq 3.16)"]
        P5["5.0 SOAR Response & Action Dispatch"]
    end

    subgraph Stores
        D1[("D1: events")]
        D2[("D2: session_summaries")]
        D3[("D3: answer_records")]
        D4[("D4: teacher_actions")]
        D5[("D5: course_students")]
    end

    E1 -->|Raw Browser Events| P1
    E2 -->|Roster & Exam Metadata| D5
    P1 -->|Validated Events| D1
    P1 -->|Counters| P2
    P2 -->|B_i & N_i Sub-scores| P4
    
    D3 -->|Essay Texts >= 30 words| P3
    P3 <-->|Analysis Request/Response| E4
    P3 -->|A_i & S_i Sub-scores| P4

    P4 -->|Update Risk & Categories| D2
    D2 -->|Live Stream & Risk Matrices| E3

    E3 -->|Action: Message/Lock/Time| P5
    P5 -->|Logged Action| D4
    D4 -->|Active Directive| E1
```

---

## 4. Key Mathematical Equations Implemented

### 4.1. Availability-Adjusted Risk Formula (Thesis Eq 3.16)
$$R_{i,\%} = 100 \times \frac{\sum_{k \in K_i} w_k X_{k,i}}{\sum_{k \in K_i} w_k}$$

Where:
* $w_B = \frac{4}{15} \approx 0.2667$ (Behavioral Sub-Score)
* $w_A = \frac{3}{15} = 0.2000$ (GenAI Content Detection Sub-Score)
* $w_S = \frac{4}{15} \approx 0.2667$ (Textual Similarity Sub-Score)
* $w_N = \frac{4}{15} \approx 0.2667$ (Network & Connectivity Sub-Score)

### 4.2. Behavioral Sub-Score Normalization (Thesis Eq 3.2 – 3.6)
$$B_i = \frac{n_F + n_D + n_P}{3}$$

Where:
* $n_F = \min\left(1, \frac{F_i}{Q_i}\right)$ ($F_i = \text{tab\_hidden} + \text{page\_leave} + \text{blur}$)
* $n_D = \min\left(1, \frac{D_i}{T_i}\right)$ ($D_i = \text{total absence duration in seconds}$)
* $n_P = \min\left(1, \frac{P_i}{2 \cdot Q_i}\right)$ ($P_i = \text{paste\_count} + \text{copy\_count}$)

### 4.3. Textual Word Trigram Cosine Similarity (Thesis Eq 3.9 – 3.11)
$$\text{Cosine}(A, B) = \frac{\mathbf{v}_A \cdot \mathbf{v}_B}{\|\mathbf{v}_A\| \|\mathbf{v}_B\|} = \frac{\sum_{t} f_A(t) f_B(t)}{\sqrt{\sum_t f_A(t)^2} \sqrt{\sum_t f_B(t)^2}}$$
$$m_{iq} = \begin{cases} 1 & \text{if } \max_{j \neq i} \text{Cosine}(A_{iq}, A_{jq}) \ge \tau \ (0.75) \\ 0 & \text{otherwise} \end{cases}$$
$$S_i = \frac{1}{Q_S} \sum_{q=1}^{Q_S} m_{iq}$$

---

## 5. NIST SP 800-30 Risk Levels (Thesis Table 3.1)

| Risk Range (%) | Risk Level | Label (Arabic) | Visual Indicator | SOAR Recommended Response |
|---|---|---|---|---|
| **[0.0% – 4.99%]** | `safe` | منخفض جداً | Green Badge | Normal session monitoring |
| **[5.0% – 20.99%]** | `low` | منخفض | Blue Badge | Log telemetry without active alert |
| **[21.0% – 79.99%]** | `medium` | متوسط | Amber Badge (**Alert Threshold**) | Flag student in Live Monitor for proctor attention |
| **[80.0% – 95.99%]** | `high` | مرتفع | Orange Badge | Deliver in-exam warning notification |
| **[96.0% – 100.0%]** | `critical` | مرتفع جداً | Red Glowing Badge | Teacher decision: lock session or deduct time |
