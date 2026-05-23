# Guida completa alla creazione e gestione delle Abilità

> **A chi è rivolto questo documento**
> Staff e amministratori che vogliono creare, configurare e gestire abilità, incantesimi, tecniche e rituali su Logeon.
> Il documento copre sia il pannello amministrativo che gli strumenti di gioco in-game.

---

## Indice

1. [Concetti fondamentali](#1-concetti-fondamentali)
2. [Il ciclo di vita di un'abilità](#2-il-ciclo-di-vita-di-unabilità)
3. [Creare la Definizione](#3-creare-la-definizione)
4. [Configurare le Regole di sblocco (Grant)](#4-configurare-le-regole-di-sblocco-grant)
5. [Configurare i Requisiti](#5-configurare-i-requisiti)
6. [Configurare gli Effetti](#6-configurare-gli-effetti)
7. [Il sistema dei Punti](#7-il-sistema-dei-punti)
8. [Assegnare abilità ai personaggi (Admin)](#8-assegnare-abilità-ai-personaggi-admin)
9. [Gestire le Approvazioni](#9-gestire-le-approvazioni)
10. [Progressione e livelli](#10-progressione-e-livelli)
11. [Il punto di vista del giocatore](#11-il-punto-di-vista-del-giocatore)
12. [Uso in-game](#12-uso-in-game)
13. [Esempio pratico guidato](#13-esempio-pratico-guidato)
14. [Riferimento rapido dei valori ammessi](#14-riferimento-rapido-dei-valori-ammessi)

---

## 1. Concetti fondamentali

Il sistema abilità è strutturato attorno a quattro entità principali:

| Entità | Descrizione |
|--------|-------------|
| **Definizione abilità** | Il blueprint dell'abilità: nome, tipo, effetto, regole di progressione |
| **Grant (regola di sblocco)** | Definisce *chi* può accedere all'abilità e *come* (automaticamente o manualmente) |
| **Assegnazione personaggio** | Il record che collega un personaggio specifico a un'abilità, con il suo stato di avanzamento |
| **Punti abilità** | La valuta di progressione — separata in categorie tematiche (combattimento, magia, ecc.) |

### Quattro tipi di abilità

| Tipo | Descrizione |
|------|-------------|
| `ability` | Abilità generale — capacità acquisita tramite pratica o studio |
| `spell` | Incantesimo — arte magica che produce un effetto diretto |
| `technique` | Tecnica — metodo specializzato, spesso marziale o artigianale |
| `ritual` | Rituale — pratica cerimoniale con effetti narrativi più lenti e duraturi |

Il tipo è una categorizzazione narrativa e di interfaccia, non cambia le meccaniche di base.

### Chi fa cosa

| Azione | Staff | Giocatore |
|--------|-------|-----------|
| Creare definizioni abilità | ✅ | ❌ |
| Configurare grant, requisiti, effetti | ✅ | ❌ |
| Assegnare abilità direttamente a un personaggio | ✅ | ❌ |
| Approvare/rifiutare richieste di apprendimento | ✅ | ❌ |
| Gestire punti abilità (ricariche, log) | ✅ | ❌ |
| Richiedere l'apprendimento di un'abilità | ✅ | ✅ |
| Richiedere l'upgrade di un'abilità | ✅ | ✅ |
| Usare un'abilità in-game | ✅ | ✅ |
| Vedere il proprio elenco abilità e punti | ✅ | ✅ |

---

## 2. Il ciclo di vita di un'abilità

```
[ADMIN]
   │
   ▼
Definizione creata (is_active = Sì)
   │  configura grant, requisiti, effetti
   │
   ├── Grant "auto_learn" ─────────────────────────────────────────┐
   │       il sistema assegna automaticamente                      │
   │       → Assegnazione: status = "learned"                      │
   │                                                               │
   └── Grant "unlock" ─────────────────────────────────────────────┤
           il giocatore la vede e può richiederla                  │
           │                                                        │
           ├── requires_learning = No ────────────────────────────┐│
           │       disponibile subito, senza spendere punti        ││
           │                                                        ││
           └── requires_learning = Sì ────────────────────────────┘│
                   il giocatore spende punti per apprenderla       │
                   │                                                │
                   ├── requires_staff_approval = No               │
                   │       → status = "learned" (immediato)        │
                   │                                                │
                   └── requires_staff_approval = Sì               │
                           → status = "pending_approval"           │
                           → staff approva o rifiuta               │
                                   │                               │
                                   └────── status = "learned" ─────┘
                                                   │
                                                   ▼
                                           Abilità usabile in-game
                                           (effetti attivi, uso possibile)
```

---

## 3. Creare la Definizione

**Percorso admin:** Area Amministrativa → Abilità e incantesimi → pulsante "Nuova abilità"

### Sezione Identità

| Campo | Descrizione | Note |
|-------|-------------|------|
| **Nome** | Nome visibile ai giocatori | Es. "Colpo Devastante", "Velo delle Ombre" |
| **Slug** | Identificatore univoco interno | Generato automaticamente dal nome. Non modificare dopo la pubblicazione |
| **Tipo** | `ability`, `spell`, `technique`, `ritual` | Categorizzazione narrativa e di filtro |
| **Descrizione** | Testo narrativo e meccanico visibile al giocatore | Supporta TipTap. Spiega cosa fa l'abilità, il suo sapore narrativo, i suoi limiti |

### Sezione Uso in gioco

| Campo | Valori | Descrizione |
|-------|--------|-------------|
| **Bersaglio** | `self`, `scene` | `self` = l'effetto si applica solo al personaggio che la usa; `scene` = si applica alla scena/location corrente |
| **Effetto** | `none`, `apply_state`, `remove_state` | `none` = nessun effetto meccanico automatico (effetto gestito narrativamente); `apply_state` = applica uno stato narrativo; `remove_state` = rimuove uno stato narrativo |
| **Stato narrativo** | ricerca guidata | Richiesto se effetto è `apply_state` o `remove_state`. Indica quale stato applicare/rimuovere |
| **Cooldown (secondi)** | numero intero | Tempo minimo tra un uso e il successivo. `0` = nessun cooldown. 3600 = 1 ora, 86400 = 1 giorno |
| **Livello massimo** | numero ≥ 1 | Quante volte l'abilità può essere potenziata. `1` = nessuna progressione, solo apprendimento base |
| **Ordine** | numero intero | Posizione nelle liste. Usa valori a gap (10, 20…). Default: 100 |

#### Effetto — Dettaglio

- **`none`** — L'abilità viene usata in-game ma non produce cambiamenti automatici al personaggio o alla scena. Utile per abilità puramente narrative (es. "Senso del pericolo" che il giocatore descrive liberamente).
- **`apply_state`** — Al momento dell'uso, il motore applica lo stato narrativo selezionato al bersaglio. Lo stato deve esistere già nel sistema.
- **`remove_state`** — Al momento dell'uso, rimuove lo stato narrativo selezionato dal bersaglio. Usato per abilità di "dispel", purificazione, ecc.

### Sezione Progressione e visibilità

| Campo | Valori | Descrizione |
|-------|--------|-------------|
| **Categoria punti** | `combat`, `magic`, `social`, `crafting`, `knowledge` | Da quale pool di punti il giocatore preleva per apprendere/potenziare l'abilità |
| **Attiva** | Sì / No | Se No, l'abilità non è disponibile in nessun contesto |
| **Pubblica** | Sì / No | Se Sì, appare nel catalogo pubblico visibile a tutti i giocatori. Se No, è visibile solo tramite grant o assegnazione diretta |
| **Nascondi se bloccata** | Sì / No | Se Sì, i giocatori che non hanno accesso non la vedono nemmeno nelle liste |
| **Richiede apprendimento** | Sì / No | Se No, il giocatore non deve spendere punti: l'abilità è immediatamente disponibile all'unlock. Se Sì, deve spendere i punti richiesti |
| **Richiede approvazione staff** | Sì / No | Se Sì, ogni richiesta di apprendimento o upgrade entra in coda per la revisione dello staff |

### Sezione Metadati e preset

Il campo metadati accetta JSON libero per configurazioni aggiuntive. Usa i **preset** disponibili come punto di partenza:

| Preset | JSON generato | Uso |
|--------|--------------|-----|
| ui_icon | `{"ui_icon": "bi bi-stars"}` | Icona Bootstrap Icons mostrata nell'interfaccia |
| ui_audio | `{"ui_audio": "spell-chime", "ui_variant": "soft"}` | Suono riprodotto all'uso |
| combat_attack | `{"ui_icon": "bi bi-lightning-charge", "family": "combat", "slot": "attack"}` | Abilità di attacco standard |
| magic_spell | `{"ui_icon": "bi bi-magic", "family": "magic", "slot": "spell"}` | Incantesimo magico standard |

Puoi combinare più chiavi nello stesso JSON: `{"ui_icon": "bi bi-fire", "family": "magic", "slot": "spell", "ui_audio": "spell-chime"}`.

### Salvataggio

- **"Salva e chiudi"** — salva la definizione e chiude la modale.
- **"Salva e continua"** — salva e mantiene la modale aperta per configurare le regole di sblocco, requisiti ed effetti. **Consigliato** per un flusso di lavoro completo.

---

## 4. Configurare le Regole di sblocco (Grant)

I grant definiscono **chi ottiene accesso** all'abilità e **come**. Senza almeno un grant, l'abilità non è accessibile a nessun personaggio (a meno di assegnazione diretta).

**Percorso:** Dettaglio abilità → sezione "Regole abilità" → tab Grant

### Campi del grant

| Campo | Valori | Descrizione |
|-------|--------|-------------|
| **Tipo sorgente** | `character`, `archetype`, `guild`, `custom` | Da cosa dipende lo sblocco |
| **ID sorgente** | numero | L'ID specifico del personaggio, archetipo o gilda. Non usato per `custom` |
| **Modalità** | `unlock`, `auto_learn`, `bonus`, `forbid` | Come viene concessa l'abilità (vedi sotto) |
| **Retention policy** | `keep_when_lost`, `while_source_active`, `disable_when_lost`, `refund_when_lost` | Cosa succede se la sorgente viene persa |
| **Grado minimo** | numero | Solo personaggi di questo grado o superiore ricevono il grant. `0` = nessun limite |
| **Grado massimo** | numero | Solo personaggi fino a questo grado ricevono il grant. `0` = nessun limite |
| **Attiva** | Sì / No | Disattiva temporaneamente il grant senza eliminarlo |
| **Priorità** | numero | Ordine di elaborazione. Valori più bassi = elaborazione prima |
| **Metadati** | JSON | `label`, `visibility: 'staff_only'`, `source_window: true` |

### Modalità grant — Dettaglio

| Modalità | Descrizione |
|----------|-------------|
| `unlock` | Il personaggio vede l'abilità come disponibile e può richiedere di apprenderla (se `requires_learning = Sì`) o usarla direttamente (se `requires_learning = No`) |
| `auto_learn` | Il personaggio acquisisce l'abilità automaticamente senza alcun intervento, senza spendere punti. Lo status diventa immediatamente `learned` |
| `bonus` | Conferisce l'abilità come bonus aggiuntivo — utile per grant multipli sulla stessa abilità da sorgenti diverse |
| `forbid` | Blocca esplicitamente l'accesso all'abilità per questa sorgente, anche se altri grant la concederebbero |

### Retention policy — Cosa succede quando la sorgente viene persa

| Policy | Descrizione |
|--------|-------------|
| `keep_when_lost` | L'abilità rimane appresa permanentemente, anche se l'archetipo/gilda viene rimosso |
| `while_source_active` | L'abilità è disponibile solo finché la sorgente è attiva. Se persa → status torna a `available` |
| `disable_when_lost` | Se la sorgente viene persa, l'abilità diventa `disabled` (non rimossa, ma inutilizzabile) |
| `refund_when_lost` | Se la sorgente viene persa, i punti spesi vengono rimborsati e l'abilità torna a `available` |

### Esempi pratici di grant

**Tutti i personaggi dell'archetipo "Guerriero" sbloccano automaticamente "Colpo Base":**
```
Tipo sorgente:  archetype
ID sorgente:    [id dell'archetipo Guerriero]
Modalità:       auto_learn
Retention:      keep_when_lost
Grado min:      0
```

**Solo i membri della Gilda degli Assassini (grado ≥ 3) sbloccano "Velo delle Ombre":**
```
Tipo sorgente:  guild
ID sorgente:    [id della gilda]
Modalità:       unlock
Retention:      while_source_active
Grado min:      3
```

**Un personaggio specifico ha accesso unico a un'abilità rara:**
```
Tipo sorgente:  character
ID sorgente:    [id del personaggio]
Modalità:       unlock
Retention:      keep_when_lost
```

---

## 5. Configurare i Requisiti

I requisiti definiscono **le condizioni che il personaggio deve soddisfare** per poter apprendere o potenziare l'abilità. Ogni requisito può essere specifico per un livello dell'abilità.

**Percorso:** Dettaglio abilità → sezione "Regole abilità" → tab Requisiti

### Campi del requisito

| Campo | Valori | Descrizione |
|-------|--------|-------------|
| **Livello abilità** | numero | A quale livello dell'abilità si applica questo requisito. `1` = per l'apprendimento base |
| **Tipo** | `attribute`, `rank`, `ability`, `archetype`, `guild`, `custom` | Cosa viene verificato |
| **Chiave** | testo | La chiave specifica da verificare (es. slug dell'attributo, livello del grado) |
| **Operatore** | `=`, `!=`, `>`, `>=`, `<`, `<=` | Come confrontare il valore attuale con quello richiesto |
| **Valore richiesto** | testo/numero | La soglia da raggiungere |
| **Policy se non soddisfatto** | `block`, `ignore`, `hide` | Cosa succede se il requisito non è soddisfatto |
| **Nascosto al player** | Sì / No | Se Sì, il requisito non viene mostrato all'utente (requisito "segreto") |
| **Attivo** | Sì / No | Disattiva temporaneamente il requisito |
| **Metadati** | JSON | `note`, `staff_hint`, `visibility: 'hidden'` |

### Tipo requisito — Dettaglio

| Tipo | Cosa verifica | Chiave esempio |
|------|--------------|----------------|
| `attribute` | Un attributo del personaggio (forza, agilità, ecc.) | `forza`, `magia`, `difesa` |
| `rank` | Il grado attuale del personaggio | valore numerico (es. `3`) |
| `ability` | Se il personaggio ha già appreso un'altra abilità | slug dell'abilità prerequisita |
| `archetype` | Se il personaggio ha un certo archetipo | ID o slug dell'archetipo |
| `guild` | Se il personaggio appartiene a una gilda | ID della gilda |
| `custom` | Verifica personalizzata estensibile | chiave custom definita dal sistema |

### Policy quando non soddisfatto

| Policy | Effetto |
|--------|---------|
| `block` | Il giocatore non può apprendere/potenziare l'abilità. Il requisito viene mostrato come non soddisfatto nell'interfaccia |
| `ignore` | Il requisito non viene controllato (disattivato nella pratica senza eliminarlo) |
| `hide` | Come `block`, ma l'abilità stessa viene nascosta se il requisito non è soddisfatto |

### Esempi pratici

**L'abilità "Colpo Pesante" richiede Forza ≥ 8:**
```
Livello:        1
Tipo:           attribute
Chiave:         forza
Operatore:      >=
Valore:         8
Policy:         block
Nascosto:       No
```

**L'upgrade al livello 2 richiede grado ≥ 3:**
```
Livello:        2
Tipo:           rank
Chiave:         (il valore del grado)
Operatore:      >=
Valore:         3
Policy:         block
```

**Prerequisito silenzioso (il giocatore non sa del requisito):**
```
Livello:        1
Tipo:           ability
Chiave:         [slug abilità prerequisita]
Operatore:      =
Valore:         learned
Policy:         block
Nascosto:       Sì
Metadati:       {"staff_hint": "Verificare se ha già appreso Tecnica Base"}
```

---

## 6. Configurare gli Effetti

Gli effetti definiscono **le modifiche meccaniche passive o attive** che l'abilità produce sul personaggio o sulla scena. Non tutte le abilità hanno effetti meccanici — molte funzionano puramente a livello narrativo.

**Percorso:** Dettaglio abilità → sezione "Regole abilità" → tab Effetti

### Campi dell'effetto

| Campo | Valori | Descrizione |
|-------|--------|-------------|
| **Livello abilità** | numero | A quale livello si attiva questo effetto |
| **Tipo effetto** | `modifier`, `narrative_state`, `custom` | Natura dell'effetto |
| **Sistema target** | `character_attributes`, `narrative_states`, `custom` | Su quale sistema agisce |
| **Chiave target** | testo | L'attributo o lo stato specifico da modificare (es. `forza`, `difesa`) |
| **Operazione** | `add`, `apply`, `remove` | Come l'effetto agisce sul target |
| **Valore** | numero decimale | La quantità della modifica (per operazioni numeriche) |
| **Policy di attivazione** | vedi tabella | Quando l'effetto è attivo |
| **Policy se non disponibile** | `ignore`, `block`, `hide` | Cosa succede se l'effetto non può essere applicato |
| **Attivo** | Sì / No | Disattiva l'effetto senza eliminarlo |
| **Metadati** | JSON | `label`, `display_mode`, `aura_scope`, `duration_turns` |

### Policy di attivazione — Dettaglio

| Policy | Quando l'effetto è attivo |
|--------|--------------------------|
| `while_ability_learned` | Sempre attivo, non appena l'abilità è appresa (effetto passivo permanente) |
| `while_ability_usable` | Attivo solo quando l'abilità è in stato utilizzabile (non in cooldown, requisiti soddisfatti) |
| `on_use` | Si attiva una sola volta nel momento dell'uso dell'abilità |
| `manual_toggle` | Il giocatore attiva/disattiva manualmente l'effetto |
| `temporary` | Effetto a durata limitata (configurabile via metadati `duration_turns`) |

### Esempi pratici

**"Pelle di Pietra": bonus passivo +3 alla difesa quando l'abilità è appresa:**
```
Livello:           1
Tipo effetto:      modifier
Sistema target:    character_attributes
Chiave target:     difesa
Operazione:        add
Valore:            3
Attivazione:       while_ability_learned
Metadati:          {"label": "Bonus difesa passivo", "display_mode": "passive"}
```

**"Maledizione": applica uno stato narrativo al bersaglio quando usata:**
```
Livello:           1
Tipo effetto:      narrative_state
Sistema target:    narrative_states
Chiave target:     [slug dello stato "Maledetto"]
Operazione:        apply
Valore:            1
Attivazione:       on_use
Metadati:          {"label": "Applica maledizione", "duration_turns": 3}
```

---

## 7. Il sistema dei Punti

I punti abilità sono la valuta usata dai giocatori per apprendere e potenziare le abilità. Sono divisi in **cinque categorie tematiche** indipendenti.

### Categorie punti

| Slug | Nome | Per quali abilità |
|------|------|-------------------|
| `combat` | Combattimento | Abilità marziali, tattiche, difensive |
| `magic` | Magia | Incantesimi, rituali, arti arcane |
| `social` | Sociale | Influenza, empatia, leadership, persuasione |
| `crafting` | Artigianato | Produzione, riparazione, mestieri |
| `knowledge` | Conoscenza | Studio, erudizione, investigazione, ricerca |

### Come i giocatori guadagnano punti

**Avanzamento di grado (automatico):** il sistema assegna automaticamente punti al personaggio quando sale di grado. La tabella "Ricompense per grado" in admin definisce quanti punti di quale categoria vengono assegnati a ogni grado.

**Percorso admin per configurare ricompense:** Admin → Abilità e incantesimi → sezione "Ricompense per grado"

| Campo | Descrizione |
|-------|-------------|
| **Grado** | A quale grado del personaggio scatta la ricompensa |
| **Categoria** | Quale pool di punti viene ricaricato |
| **Punti** | Quanti punti vengono aggiunti |
| **Attiva** | Se la riga è attiva |

**Concessione manuale da staff:** lo staff può aggiungere punti direttamente dal pannello assegnazioni, tramite log punti con causale `staff_grant`.

### Meccanica di investimento

- Il giocatore **vede i punti disponibili** nel suo pannello abilità.
- Quando richiede l'apprendimento, i punti vengono **prenotati** (`pending_points`) ma non ancora detratti definitivamente.
- Se l'approvazione staff **approva** → i punti diventano `spent_points` (spesi ufficialmente).
- Se l'approvazione staff **rifiuta** → i punti prenotati vengono **restituiti** (`pending_points` → 0), il giocatore può ritentare.

---

## 8. Assegnare abilità ai personaggi (Admin)

Oltre al meccanismo automatico dei grant, lo staff può assegnare abilità direttamente a un personaggio specifico.

**Percorso admin:** Admin → Abilità e incantesimi → sezione "Assegnazioni personaggio"

### Cercare un personaggio

Usa il campo di ricerca nella sezione assegnazioni per trovare il personaggio. Una volta selezionato, si carica la lista delle sue abilità.

### Creare un'assegnazione

Clicca "Nuova" e compila:

| Campo | Valori | Descrizione |
|-------|--------|-------------|
| **Abilità** | dropdown | L'abilità da assegnare |
| **Status** | `available`, `learning`, `pending_approval`, `learned`, `suspended`, `disabled` | Lo stato iniziale dell'abilità per quel personaggio |
| **Livello** | numero | Il livello corrente dell'abilità (0 = non appresa, 1 = appresa al primo livello) |
| **Stato approvazione** | `approved`, `pending`, `rejected` | Se lo status richiede approvazione, indica lo stato del processo |
| **Ordine** | numero | Posizione nell'elenco abilità del personaggio |

#### Quando usare l'assegnazione diretta

- Per **concedere immediatamente** un'abilità rara o narrativamente giustificata, senza passare dai grant.
- Per **correggere** uno stato errato (es. portare a `learned` un'abilità bloccata per errore tecnico).
- Per **sospendere** temporaneamente un'abilità: status = `suspended` + inserisci il motivo.
- Per **assegnare un'abilità esclusiva** a un solo personaggio senza creare un grant generico.

### Eliminare un'assegnazione

Usa il pulsante di eliminazione nella riga dell'elenco. L'eliminazione rimuove il record ma non ha effetti automatici sui punti già spesi — gestisci eventuali rimborsi manualmente.

---

## 9. Gestire le Approvazioni

Quando `requires_staff_approval = Sì`, ogni richiesta di apprendimento o upgrade da parte di un giocatore genera una notifica allo staff e appare nella coda di approvazione.

**Percorso admin:** Admin → Abilità e incantesimi → sezione "Approvazioni in attesa"

### Cosa vedi nella coda

Per ogni richiesta:
- Nome del personaggio
- Abilità richiesta
- Livello di progressione richiesto (es. "0 → 1" per primo apprendimento)

### Approvare

Clicca **"Approva"**. Il sistema:
1. Imposta `status = learned`
2. Incrementa il livello
3. Conferma i punti come spesi (`spent_points`)
4. Azzera i punti in attesa (`pending_points`)
5. Notifica il giocatore: *"Lo staff ha approvato la tua richiesta"*

### Rifiutare

Clicca **"Rifiuta"**. Il sistema:
1. Mantiene lo status precedente (torna a `learning` o al livello corrente)
2. Restituisce i punti prenotati al giocatore
3. Notifica il giocatore: *"Lo staff ha respinto la tua richiesta. I punti investiti restano salvati."*

> **Nota:** i punti non vengono persi al rifiuto — il giocatore può ritentare la richiesta quando vuole.

---

## 10. Progressione e livelli

Un'abilità può avere più livelli se `max_level > 1`. Ogni livello può avere:
- Requisiti diversi (es. grado più alto, attributo più elevato)
- Effetti diversi (es. bonus maggiore, stato più potente)
- Costo in punti diverso (configurato nella sezione "Regole per livello")

### Regole per livello

**Percorso:** Admin → Abilità e incantesimi → Dettaglio abilità → sezione "Regole per livello"

| Campo | Descrizione |
|-------|-------------|
| **Livello** | A quale livello dell'abilità si applica questa regola |
| **Punti richiesti** | Quanti punti il giocatore deve spendere per raggiungere questo livello |
| **Grado minimo** | Il grado minimo del personaggio per poter upgraddare a questo livello |
| **Richiede approvazione** | Se questo specifico upgrade richiede approvazione staff |

### Esperienza progressiva

Esempio di configurazione per un'abilità a 3 livelli:

| Livello | Punti | Grado min | Approva |
|---------|-------|-----------|---------|
| 1 | 5 | 0 | No |
| 2 | 10 | 2 | Sì |
| 3 | 20 | 4 | Sì |

---

## 11. Il punto di vista del giocatore

### Dove il giocatore vede le abilità

1. **Catalogo abilità** (navigazione → Abilità) — lista tutte le abilità pubbliche (`is_public = Sì`)
2. **Le mie abilità** — pannello personale con solo le abilità accessibili/apprese
3. **Pannello location/scena** — le abilità usabili nella scena corrente appaiono nel launcher contestuale

### Flag di interfaccia

Il sistema calcola per ogni abilità una serie di flag che determinano cosa il giocatore può fare:

| Flag | Significato |
|------|-------------|
| `visible` | L'abilità appare nell'interfaccia del giocatore |
| `available` | Il giocatore può interagire con l'abilità (vederla, richiedere) |
| `learnable` | Il giocatore può richiedere di apprenderla (tutti i requisiti soddisfatti, ha punti sufficienti) |
| `upgradeable` | Il giocatore può richiedere l'upgrade al livello successivo |
| `usable` | Il giocatore può attivare l'abilità (status = learned, cooldown scaduto, requisiti attivi soddisfatti) |

### Cosa vede il giocatore

- Le abilità con `is_hidden_when_locked = Sì` **non appaiono** se non sono accessibili.
- I requisiti con `is_hidden = Sì` **non appaiono** nella lista requisiti.
- I grant con `visibility: staff_only` nei metadati **non sono visibili** al giocatore.
- I punti disponibili, spesi e storici sono visibili nel pannello personale.

---

## 12. Uso in-game

### Usare un'abilità

Quando un personaggio ha un'abilità con status `learned` e l'abilità è `usable`:

1. Il giocatore apre il **launcher scena** o il **pannello utilità location**
2. L'abilità appare come azione disponibile
3. Il giocatore la attiva
4. Il motore esegue l'effetto configurato:
   - `apply_state` → applica lo stato narrativo al bersaglio
   - `remove_state` → rimuove lo stato narrativo dal bersaglio
   - `none` → registra l'uso nel log, nessun effetto automatico
5. Il cooldown parte (se configurato)

### Bersaglio

- **`self`** → l'effetto viene applicato al personaggio che ha usato l'abilità
- **`scene`** → l'effetto viene applicato alla scena corrente (tutti i presenti o la location stessa, a seconda dello stato narrativo)

---

## 13. Esempio pratico guidato

L'esempio seguente descrive passo per passo la creazione di un'abilità con progressione a due livelli, disponibile per i personaggi dell'archetipo "Mago" a partire dal grado 2, con un effetto meccanico al primo livello.

### Scenario narrativo

> *L'abilità "Scudo Arcano" permette al mago di avvolgersi in una protezione magica. Al primo livello è un semplice schermo; al secondo livello diventa più robusto e lascia una traccia luminosa visibile nella scena.*

---

### Passo 1 — Crea la Definizione

Vai in **Admin → Abilità e incantesimi → Nuova abilità** e compila:

```
Nome:                  Scudo Arcano
Tipo:                  spell
Descrizione:           Un antico incantesimo di protezione. Il mago canalizza
                       energia arcana per creare un campo difensivo invisibile
                       che devia colpi e magie minori.

Bersaglio:             self
Effetto:               none        ← al livello 1 è solo narrativo
Cooldown:              3600        ← 1 ora tra un uso e il successivo
Livello massimo:       2
Ordine:                20
Attiva:                Sì
Pubblica:              Sì
Nascondi se bloccata:  Sì          ← chi non è Mago non la vede
Richiede apprendimento: Sì
Richiede approvazione: Sì          ← ogni apprendimento va approvato dallo staff

Categoria punti:       magic
Metadati:              {"ui_icon": "bi bi-shield-fill", "family": "magic"}
```

Clicca **"Salva e continua"**.

---

### Passo 2 — Aggiungi il Grant per i Maghi

Nella sezione "Regole abilità" → tab **Grant**, crea:

```
Tipo sorgente:  archetype
ID sorgente:    [id dell'archetipo Mago]
Modalità:       unlock
Retention:      keep_when_lost
Grado minimo:   2
Grado massimo:  0  ← nessun limite superiore
Attiva:         Sì
Priorità:       10
```

I personaggi con archetipo Mago e grado ≥ 2 vedono l'abilità come disponibile.

---

### Passo 3 — Aggiungi i Requisiti

Tab **Requisiti**:

**Requisito per il livello 1 (apprendimento base):**
```
Livello:        1
Tipo:           attribute
Chiave:         magia
Operatore:      >=
Valore:         6
Policy:         block
Nascosto:       No
```

**Requisito per il livello 2 (upgrade):**
```
Livello:        2
Tipo:           rank
Chiave:         (grado)
Operatore:      >=
Valore:         3
Policy:         block
Nascosto:       No
```

---

### Passo 4 — Aggiungi l'Effetto al Livello 2

Tab **Effetti** — al secondo livello aggiungi un bonus difensivo passivo:

```
Livello:           2
Tipo effetto:      modifier
Sistema target:    character_attributes
Chiave target:     difesa
Operazione:        add
Valore:            2
Attivazione:       while_ability_learned
Metadati:          {"label": "Scudo Arcano potenziato", "display_mode": "passive"}
```

Al livello 1 nessun effetto meccanico — solo narrativo. Al livello 2, il personaggio riceve +2 alla difesa in modo permanente finché l'abilità è appresa.

---

### Passo 5 — Configura le Regole per Livello

Vai nella sezione "Regole per livello" e crea:

```
Livello 1:  punti = 8,  grado min = 0,  richiede approvazione = Sì
Livello 2:  punti = 15, grado min = 3,  richiede approvazione = Sì
```

---

### Passo 6 — Testa dal lato giocatore

1. Accedi con un personaggio Mago di grado 2 con Magia ≥ 6 e almeno 8 punti Magia disponibili.
2. Apri il **Catalogo abilità** → cerca "Scudo Arcano".
3. Verifica che sia visibile e il pulsante "Apprendi" sia attivo.
4. Clicca "Apprendi" → il sistema invia la richiesta in coda approvazione.
5. Dallo staff (admin), vai in **Approvazioni in attesa** → approva.
6. Il personaggio ora ha "Scudo Arcano" a livello 1 con status `learned`.
7. Apri la scena → l'abilità appare nel launcher con l'icona dello scudo.

---

### Riepilogo dell'esempio

| Elemento | Configurazione |
|----------|----------------|
| Tipo | `spell` |
| Accesso | Archetipo Mago, grado ≥ 2 |
| Requisito L1 | Magia ≥ 6 |
| Requisito L2 | Grado ≥ 3 |
| Costo L1 | 8 punti Magia |
| Costo L2 | 15 punti Magia |
| Effetto L1 | Nessuno meccanico (narrativo) |
| Effetto L2 | +2 Difesa passivo |
| Approvazione | Sì per entrambi i livelli |
| Cooldown | 1 ora |

---

## 14. Riferimento rapido dei valori ammessi

### Definizione abilità

| Campo | Valori validi |
|-------|--------------|
| `type` | `ability` · `spell` · `technique` · `ritual` |
| `target_type` | `self` · `scene` |
| `effect_mode` | `none` · `apply_state` · `remove_state` |
| `point_category_id` | `combat` · `magic` · `social` · `crafting` · `knowledge` |

### Assegnazione personaggio

| Campo | Valori validi |
|-------|--------------|
| `status` | `available` · `learning` · `pending_approval` · `learned` · `suspended` · `disabled` |
| `approval_status` | `pending` · `approved` · `rejected` |

### Grant

| Campo | Valori validi |
|-------|--------------|
| `source_type` | `character` · `archetype` · `guild` · `custom` |
| `grant_mode` | `unlock` · `auto_learn` · `bonus` · `forbid` |
| `retention_policy` | `keep_when_lost` · `while_source_active` · `disable_when_lost` · `refund_when_lost` |

### Requisiti

| Campo | Valori validi |
|-------|--------------|
| `requirement_type` | `attribute` · `rank` · `ability` · `archetype` · `guild` · `custom` |
| `operator` | `=` · `!=` · `>` · `>=` · `<` · `<=` |
| `policy_when_unavailable` | `block` · `ignore` · `hide` |

### Effetti

| Campo | Valori validi |
|-------|--------------|
| `effect_type` | `modifier` · `narrative_state` · `custom` |
| `target_system` | `character_attributes` · `narrative_states` · `custom` |
| `operation` | `add` · `apply` · `remove` |
| `activation_policy` | `while_ability_learned` · `while_ability_usable` · `on_use` · `manual_toggle` · `temporary` |
| `policy_when_unavailable` | `ignore` · `block` · `hide` |

### Categorie punti (slug)

| Slug | Nome |
|------|------|
| `combat` | Combattimento |
| `magic` | Magia |
| `social` | Sociale |
| `crafting` | Artigianato |
| `knowledge` | Conoscenza |

