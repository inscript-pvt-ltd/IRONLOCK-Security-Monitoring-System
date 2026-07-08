// GENERATED CODE - DO NOT MODIFY BY HAND

part of 'offline_queue_db.dart';

// ignore_for_file: type=lint
class $GpsQueueTable extends GpsQueue
    with TableInfo<$GpsQueueTable, GpsQueueData> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $GpsQueueTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<int> id = GeneratedColumn<int>(
    'id',
    aliasedName,
    false,
    hasAutoIncrement: true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'PRIMARY KEY AUTOINCREMENT',
    ),
  );
  static const VerificationMeta _shiftIdMeta = const VerificationMeta(
    'shiftId',
  );
  @override
  late final GeneratedColumn<String> shiftId = GeneratedColumn<String>(
    'shift_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _latitudeMeta = const VerificationMeta(
    'latitude',
  );
  @override
  late final GeneratedColumn<double> latitude = GeneratedColumn<double>(
    'latitude',
    aliasedName,
    false,
    type: DriftSqlType.double,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _longitudeMeta = const VerificationMeta(
    'longitude',
  );
  @override
  late final GeneratedColumn<double> longitude = GeneratedColumn<double>(
    'longitude',
    aliasedName,
    false,
    type: DriftSqlType.double,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _accuracyMeta = const VerificationMeta(
    'accuracy',
  );
  @override
  late final GeneratedColumn<double> accuracy = GeneratedColumn<double>(
    'accuracy',
    aliasedName,
    true,
    type: DriftSqlType.double,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _batteryMeta = const VerificationMeta(
    'battery',
  );
  @override
  late final GeneratedColumn<double> battery = GeneratedColumn<double>(
    'battery',
    aliasedName,
    true,
    type: DriftSqlType.double,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _recordedAtMeta = const VerificationMeta(
    'recordedAt',
  );
  @override
  late final GeneratedColumn<String> recordedAt = GeneratedColumn<String>(
    'recorded_at',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _attemptsMeta = const VerificationMeta(
    'attempts',
  );
  @override
  late final GeneratedColumn<int> attempts = GeneratedColumn<int>(
    'attempts',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _nextAttemptMeta = const VerificationMeta(
    'nextAttempt',
  );
  @override
  late final GeneratedColumn<int> nextAttempt = GeneratedColumn<int>(
    'next_attempt',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _createdAtMeta = const VerificationMeta(
    'createdAt',
  );
  @override
  late final GeneratedColumn<int> createdAt = GeneratedColumn<int>(
    'created_at',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    shiftId,
    latitude,
    longitude,
    accuracy,
    battery,
    recordedAt,
    attempts,
    nextAttempt,
    createdAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'gps_queue';
  @override
  VerificationContext validateIntegrity(
    Insertable<GpsQueueData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    }
    if (data.containsKey('shift_id')) {
      context.handle(
        _shiftIdMeta,
        shiftId.isAcceptableOrUnknown(data['shift_id']!, _shiftIdMeta),
      );
    } else if (isInserting) {
      context.missing(_shiftIdMeta);
    }
    if (data.containsKey('latitude')) {
      context.handle(
        _latitudeMeta,
        latitude.isAcceptableOrUnknown(data['latitude']!, _latitudeMeta),
      );
    } else if (isInserting) {
      context.missing(_latitudeMeta);
    }
    if (data.containsKey('longitude')) {
      context.handle(
        _longitudeMeta,
        longitude.isAcceptableOrUnknown(data['longitude']!, _longitudeMeta),
      );
    } else if (isInserting) {
      context.missing(_longitudeMeta);
    }
    if (data.containsKey('accuracy')) {
      context.handle(
        _accuracyMeta,
        accuracy.isAcceptableOrUnknown(data['accuracy']!, _accuracyMeta),
      );
    }
    if (data.containsKey('battery')) {
      context.handle(
        _batteryMeta,
        battery.isAcceptableOrUnknown(data['battery']!, _batteryMeta),
      );
    }
    if (data.containsKey('recorded_at')) {
      context.handle(
        _recordedAtMeta,
        recordedAt.isAcceptableOrUnknown(data['recorded_at']!, _recordedAtMeta),
      );
    } else if (isInserting) {
      context.missing(_recordedAtMeta);
    }
    if (data.containsKey('attempts')) {
      context.handle(
        _attemptsMeta,
        attempts.isAcceptableOrUnknown(data['attempts']!, _attemptsMeta),
      );
    }
    if (data.containsKey('next_attempt')) {
      context.handle(
        _nextAttemptMeta,
        nextAttempt.isAcceptableOrUnknown(
          data['next_attempt']!,
          _nextAttemptMeta,
        ),
      );
    }
    if (data.containsKey('created_at')) {
      context.handle(
        _createdAtMeta,
        createdAt.isAcceptableOrUnknown(data['created_at']!, _createdAtMeta),
      );
    } else if (isInserting) {
      context.missing(_createdAtMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  GpsQueueData map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return GpsQueueData(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}id'],
      )!,
      shiftId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}shift_id'],
      )!,
      latitude: attachedDatabase.typeMapping.read(
        DriftSqlType.double,
        data['${effectivePrefix}latitude'],
      )!,
      longitude: attachedDatabase.typeMapping.read(
        DriftSqlType.double,
        data['${effectivePrefix}longitude'],
      )!,
      accuracy: attachedDatabase.typeMapping.read(
        DriftSqlType.double,
        data['${effectivePrefix}accuracy'],
      ),
      battery: attachedDatabase.typeMapping.read(
        DriftSqlType.double,
        data['${effectivePrefix}battery'],
      ),
      recordedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}recorded_at'],
      )!,
      attempts: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}attempts'],
      )!,
      nextAttempt: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}next_attempt'],
      )!,
      createdAt: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}created_at'],
      )!,
    );
  }

  @override
  $GpsQueueTable createAlias(String alias) {
    return $GpsQueueTable(attachedDatabase, alias);
  }
}

class GpsQueueData extends DataClass implements Insertable<GpsQueueData> {
  final int id;
  final String shiftId;
  final double latitude;
  final double longitude;
  final double? accuracy;
  final double? battery;
  final String recordedAt;
  final int attempts;
  final int nextAttempt;
  final int createdAt;
  const GpsQueueData({
    required this.id,
    required this.shiftId,
    required this.latitude,
    required this.longitude,
    this.accuracy,
    this.battery,
    required this.recordedAt,
    required this.attempts,
    required this.nextAttempt,
    required this.createdAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<int>(id);
    map['shift_id'] = Variable<String>(shiftId);
    map['latitude'] = Variable<double>(latitude);
    map['longitude'] = Variable<double>(longitude);
    if (!nullToAbsent || accuracy != null) {
      map['accuracy'] = Variable<double>(accuracy);
    }
    if (!nullToAbsent || battery != null) {
      map['battery'] = Variable<double>(battery);
    }
    map['recorded_at'] = Variable<String>(recordedAt);
    map['attempts'] = Variable<int>(attempts);
    map['next_attempt'] = Variable<int>(nextAttempt);
    map['created_at'] = Variable<int>(createdAt);
    return map;
  }

  GpsQueueCompanion toCompanion(bool nullToAbsent) {
    return GpsQueueCompanion(
      id: Value(id),
      shiftId: Value(shiftId),
      latitude: Value(latitude),
      longitude: Value(longitude),
      accuracy: accuracy == null && nullToAbsent
          ? const Value.absent()
          : Value(accuracy),
      battery: battery == null && nullToAbsent
          ? const Value.absent()
          : Value(battery),
      recordedAt: Value(recordedAt),
      attempts: Value(attempts),
      nextAttempt: Value(nextAttempt),
      createdAt: Value(createdAt),
    );
  }

  factory GpsQueueData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return GpsQueueData(
      id: serializer.fromJson<int>(json['id']),
      shiftId: serializer.fromJson<String>(json['shiftId']),
      latitude: serializer.fromJson<double>(json['latitude']),
      longitude: serializer.fromJson<double>(json['longitude']),
      accuracy: serializer.fromJson<double?>(json['accuracy']),
      battery: serializer.fromJson<double?>(json['battery']),
      recordedAt: serializer.fromJson<String>(json['recordedAt']),
      attempts: serializer.fromJson<int>(json['attempts']),
      nextAttempt: serializer.fromJson<int>(json['nextAttempt']),
      createdAt: serializer.fromJson<int>(json['createdAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<int>(id),
      'shiftId': serializer.toJson<String>(shiftId),
      'latitude': serializer.toJson<double>(latitude),
      'longitude': serializer.toJson<double>(longitude),
      'accuracy': serializer.toJson<double?>(accuracy),
      'battery': serializer.toJson<double?>(battery),
      'recordedAt': serializer.toJson<String>(recordedAt),
      'attempts': serializer.toJson<int>(attempts),
      'nextAttempt': serializer.toJson<int>(nextAttempt),
      'createdAt': serializer.toJson<int>(createdAt),
    };
  }

  GpsQueueData copyWith({
    int? id,
    String? shiftId,
    double? latitude,
    double? longitude,
    Value<double?> accuracy = const Value.absent(),
    Value<double?> battery = const Value.absent(),
    String? recordedAt,
    int? attempts,
    int? nextAttempt,
    int? createdAt,
  }) => GpsQueueData(
    id: id ?? this.id,
    shiftId: shiftId ?? this.shiftId,
    latitude: latitude ?? this.latitude,
    longitude: longitude ?? this.longitude,
    accuracy: accuracy.present ? accuracy.value : this.accuracy,
    battery: battery.present ? battery.value : this.battery,
    recordedAt: recordedAt ?? this.recordedAt,
    attempts: attempts ?? this.attempts,
    nextAttempt: nextAttempt ?? this.nextAttempt,
    createdAt: createdAt ?? this.createdAt,
  );
  GpsQueueData copyWithCompanion(GpsQueueCompanion data) {
    return GpsQueueData(
      id: data.id.present ? data.id.value : this.id,
      shiftId: data.shiftId.present ? data.shiftId.value : this.shiftId,
      latitude: data.latitude.present ? data.latitude.value : this.latitude,
      longitude: data.longitude.present ? data.longitude.value : this.longitude,
      accuracy: data.accuracy.present ? data.accuracy.value : this.accuracy,
      battery: data.battery.present ? data.battery.value : this.battery,
      recordedAt: data.recordedAt.present
          ? data.recordedAt.value
          : this.recordedAt,
      attempts: data.attempts.present ? data.attempts.value : this.attempts,
      nextAttempt: data.nextAttempt.present
          ? data.nextAttempt.value
          : this.nextAttempt,
      createdAt: data.createdAt.present ? data.createdAt.value : this.createdAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('GpsQueueData(')
          ..write('id: $id, ')
          ..write('shiftId: $shiftId, ')
          ..write('latitude: $latitude, ')
          ..write('longitude: $longitude, ')
          ..write('accuracy: $accuracy, ')
          ..write('battery: $battery, ')
          ..write('recordedAt: $recordedAt, ')
          ..write('attempts: $attempts, ')
          ..write('nextAttempt: $nextAttempt, ')
          ..write('createdAt: $createdAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    shiftId,
    latitude,
    longitude,
    accuracy,
    battery,
    recordedAt,
    attempts,
    nextAttempt,
    createdAt,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is GpsQueueData &&
          other.id == this.id &&
          other.shiftId == this.shiftId &&
          other.latitude == this.latitude &&
          other.longitude == this.longitude &&
          other.accuracy == this.accuracy &&
          other.battery == this.battery &&
          other.recordedAt == this.recordedAt &&
          other.attempts == this.attempts &&
          other.nextAttempt == this.nextAttempt &&
          other.createdAt == this.createdAt);
}

class GpsQueueCompanion extends UpdateCompanion<GpsQueueData> {
  final Value<int> id;
  final Value<String> shiftId;
  final Value<double> latitude;
  final Value<double> longitude;
  final Value<double?> accuracy;
  final Value<double?> battery;
  final Value<String> recordedAt;
  final Value<int> attempts;
  final Value<int> nextAttempt;
  final Value<int> createdAt;
  const GpsQueueCompanion({
    this.id = const Value.absent(),
    this.shiftId = const Value.absent(),
    this.latitude = const Value.absent(),
    this.longitude = const Value.absent(),
    this.accuracy = const Value.absent(),
    this.battery = const Value.absent(),
    this.recordedAt = const Value.absent(),
    this.attempts = const Value.absent(),
    this.nextAttempt = const Value.absent(),
    this.createdAt = const Value.absent(),
  });
  GpsQueueCompanion.insert({
    this.id = const Value.absent(),
    required String shiftId,
    required double latitude,
    required double longitude,
    this.accuracy = const Value.absent(),
    this.battery = const Value.absent(),
    required String recordedAt,
    this.attempts = const Value.absent(),
    this.nextAttempt = const Value.absent(),
    required int createdAt,
  }) : shiftId = Value(shiftId),
       latitude = Value(latitude),
       longitude = Value(longitude),
       recordedAt = Value(recordedAt),
       createdAt = Value(createdAt);
  static Insertable<GpsQueueData> custom({
    Expression<int>? id,
    Expression<String>? shiftId,
    Expression<double>? latitude,
    Expression<double>? longitude,
    Expression<double>? accuracy,
    Expression<double>? battery,
    Expression<String>? recordedAt,
    Expression<int>? attempts,
    Expression<int>? nextAttempt,
    Expression<int>? createdAt,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (shiftId != null) 'shift_id': shiftId,
      if (latitude != null) 'latitude': latitude,
      if (longitude != null) 'longitude': longitude,
      if (accuracy != null) 'accuracy': accuracy,
      if (battery != null) 'battery': battery,
      if (recordedAt != null) 'recorded_at': recordedAt,
      if (attempts != null) 'attempts': attempts,
      if (nextAttempt != null) 'next_attempt': nextAttempt,
      if (createdAt != null) 'created_at': createdAt,
    });
  }

  GpsQueueCompanion copyWith({
    Value<int>? id,
    Value<String>? shiftId,
    Value<double>? latitude,
    Value<double>? longitude,
    Value<double?>? accuracy,
    Value<double?>? battery,
    Value<String>? recordedAt,
    Value<int>? attempts,
    Value<int>? nextAttempt,
    Value<int>? createdAt,
  }) {
    return GpsQueueCompanion(
      id: id ?? this.id,
      shiftId: shiftId ?? this.shiftId,
      latitude: latitude ?? this.latitude,
      longitude: longitude ?? this.longitude,
      accuracy: accuracy ?? this.accuracy,
      battery: battery ?? this.battery,
      recordedAt: recordedAt ?? this.recordedAt,
      attempts: attempts ?? this.attempts,
      nextAttempt: nextAttempt ?? this.nextAttempt,
      createdAt: createdAt ?? this.createdAt,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<int>(id.value);
    }
    if (shiftId.present) {
      map['shift_id'] = Variable<String>(shiftId.value);
    }
    if (latitude.present) {
      map['latitude'] = Variable<double>(latitude.value);
    }
    if (longitude.present) {
      map['longitude'] = Variable<double>(longitude.value);
    }
    if (accuracy.present) {
      map['accuracy'] = Variable<double>(accuracy.value);
    }
    if (battery.present) {
      map['battery'] = Variable<double>(battery.value);
    }
    if (recordedAt.present) {
      map['recorded_at'] = Variable<String>(recordedAt.value);
    }
    if (attempts.present) {
      map['attempts'] = Variable<int>(attempts.value);
    }
    if (nextAttempt.present) {
      map['next_attempt'] = Variable<int>(nextAttempt.value);
    }
    if (createdAt.present) {
      map['created_at'] = Variable<int>(createdAt.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('GpsQueueCompanion(')
          ..write('id: $id, ')
          ..write('shiftId: $shiftId, ')
          ..write('latitude: $latitude, ')
          ..write('longitude: $longitude, ')
          ..write('accuracy: $accuracy, ')
          ..write('battery: $battery, ')
          ..write('recordedAt: $recordedAt, ')
          ..write('attempts: $attempts, ')
          ..write('nextAttempt: $nextAttempt, ')
          ..write('createdAt: $createdAt')
          ..write(')'))
        .toString();
  }
}

class $WakefulnessQueueTable extends WakefulnessQueue
    with TableInfo<$WakefulnessQueueTable, WakefulnessQueueData> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $WakefulnessQueueTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<int> id = GeneratedColumn<int>(
    'id',
    aliasedName,
    false,
    hasAutoIncrement: true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'PRIMARY KEY AUTOINCREMENT',
    ),
  );
  static const VerificationMeta _checkIdMeta = const VerificationMeta(
    'checkId',
  );
  @override
  late final GeneratedColumn<String> checkId = GeneratedColumn<String>(
    'check_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _codeMeta = const VerificationMeta('code');
  @override
  late final GeneratedColumn<String> code = GeneratedColumn<String>(
    'code',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _windowReferenceMeta = const VerificationMeta(
    'windowReference',
  );
  @override
  late final GeneratedColumn<int> windowReference = GeneratedColumn<int>(
    'window_reference',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _respondedAtMeta = const VerificationMeta(
    'respondedAt',
  );
  @override
  late final GeneratedColumn<String> respondedAt = GeneratedColumn<String>(
    'responded_at',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _attemptsMeta = const VerificationMeta(
    'attempts',
  );
  @override
  late final GeneratedColumn<int> attempts = GeneratedColumn<int>(
    'attempts',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _nextAttemptMeta = const VerificationMeta(
    'nextAttempt',
  );
  @override
  late final GeneratedColumn<int> nextAttempt = GeneratedColumn<int>(
    'next_attempt',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _createdAtMeta = const VerificationMeta(
    'createdAt',
  );
  @override
  late final GeneratedColumn<int> createdAt = GeneratedColumn<int>(
    'created_at',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    checkId,
    code,
    windowReference,
    respondedAt,
    attempts,
    nextAttempt,
    createdAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'wakefulness_queue';
  @override
  VerificationContext validateIntegrity(
    Insertable<WakefulnessQueueData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    }
    if (data.containsKey('check_id')) {
      context.handle(
        _checkIdMeta,
        checkId.isAcceptableOrUnknown(data['check_id']!, _checkIdMeta),
      );
    } else if (isInserting) {
      context.missing(_checkIdMeta);
    }
    if (data.containsKey('code')) {
      context.handle(
        _codeMeta,
        code.isAcceptableOrUnknown(data['code']!, _codeMeta),
      );
    } else if (isInserting) {
      context.missing(_codeMeta);
    }
    if (data.containsKey('window_reference')) {
      context.handle(
        _windowReferenceMeta,
        windowReference.isAcceptableOrUnknown(
          data['window_reference']!,
          _windowReferenceMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_windowReferenceMeta);
    }
    if (data.containsKey('responded_at')) {
      context.handle(
        _respondedAtMeta,
        respondedAt.isAcceptableOrUnknown(
          data['responded_at']!,
          _respondedAtMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_respondedAtMeta);
    }
    if (data.containsKey('attempts')) {
      context.handle(
        _attemptsMeta,
        attempts.isAcceptableOrUnknown(data['attempts']!, _attemptsMeta),
      );
    }
    if (data.containsKey('next_attempt')) {
      context.handle(
        _nextAttemptMeta,
        nextAttempt.isAcceptableOrUnknown(
          data['next_attempt']!,
          _nextAttemptMeta,
        ),
      );
    }
    if (data.containsKey('created_at')) {
      context.handle(
        _createdAtMeta,
        createdAt.isAcceptableOrUnknown(data['created_at']!, _createdAtMeta),
      );
    } else if (isInserting) {
      context.missing(_createdAtMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  WakefulnessQueueData map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return WakefulnessQueueData(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}id'],
      )!,
      checkId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}check_id'],
      )!,
      code: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}code'],
      )!,
      windowReference: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}window_reference'],
      )!,
      respondedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}responded_at'],
      )!,
      attempts: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}attempts'],
      )!,
      nextAttempt: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}next_attempt'],
      )!,
      createdAt: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}created_at'],
      )!,
    );
  }

  @override
  $WakefulnessQueueTable createAlias(String alias) {
    return $WakefulnessQueueTable(attachedDatabase, alias);
  }
}

class WakefulnessQueueData extends DataClass
    implements Insertable<WakefulnessQueueData> {
  final int id;
  final String checkId;
  final String code;
  final int windowReference;
  final String respondedAt;
  final int attempts;
  final int nextAttempt;
  final int createdAt;
  const WakefulnessQueueData({
    required this.id,
    required this.checkId,
    required this.code,
    required this.windowReference,
    required this.respondedAt,
    required this.attempts,
    required this.nextAttempt,
    required this.createdAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<int>(id);
    map['check_id'] = Variable<String>(checkId);
    map['code'] = Variable<String>(code);
    map['window_reference'] = Variable<int>(windowReference);
    map['responded_at'] = Variable<String>(respondedAt);
    map['attempts'] = Variable<int>(attempts);
    map['next_attempt'] = Variable<int>(nextAttempt);
    map['created_at'] = Variable<int>(createdAt);
    return map;
  }

  WakefulnessQueueCompanion toCompanion(bool nullToAbsent) {
    return WakefulnessQueueCompanion(
      id: Value(id),
      checkId: Value(checkId),
      code: Value(code),
      windowReference: Value(windowReference),
      respondedAt: Value(respondedAt),
      attempts: Value(attempts),
      nextAttempt: Value(nextAttempt),
      createdAt: Value(createdAt),
    );
  }

  factory WakefulnessQueueData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return WakefulnessQueueData(
      id: serializer.fromJson<int>(json['id']),
      checkId: serializer.fromJson<String>(json['checkId']),
      code: serializer.fromJson<String>(json['code']),
      windowReference: serializer.fromJson<int>(json['windowReference']),
      respondedAt: serializer.fromJson<String>(json['respondedAt']),
      attempts: serializer.fromJson<int>(json['attempts']),
      nextAttempt: serializer.fromJson<int>(json['nextAttempt']),
      createdAt: serializer.fromJson<int>(json['createdAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<int>(id),
      'checkId': serializer.toJson<String>(checkId),
      'code': serializer.toJson<String>(code),
      'windowReference': serializer.toJson<int>(windowReference),
      'respondedAt': serializer.toJson<String>(respondedAt),
      'attempts': serializer.toJson<int>(attempts),
      'nextAttempt': serializer.toJson<int>(nextAttempt),
      'createdAt': serializer.toJson<int>(createdAt),
    };
  }

  WakefulnessQueueData copyWith({
    int? id,
    String? checkId,
    String? code,
    int? windowReference,
    String? respondedAt,
    int? attempts,
    int? nextAttempt,
    int? createdAt,
  }) => WakefulnessQueueData(
    id: id ?? this.id,
    checkId: checkId ?? this.checkId,
    code: code ?? this.code,
    windowReference: windowReference ?? this.windowReference,
    respondedAt: respondedAt ?? this.respondedAt,
    attempts: attempts ?? this.attempts,
    nextAttempt: nextAttempt ?? this.nextAttempt,
    createdAt: createdAt ?? this.createdAt,
  );
  WakefulnessQueueData copyWithCompanion(WakefulnessQueueCompanion data) {
    return WakefulnessQueueData(
      id: data.id.present ? data.id.value : this.id,
      checkId: data.checkId.present ? data.checkId.value : this.checkId,
      code: data.code.present ? data.code.value : this.code,
      windowReference: data.windowReference.present
          ? data.windowReference.value
          : this.windowReference,
      respondedAt: data.respondedAt.present
          ? data.respondedAt.value
          : this.respondedAt,
      attempts: data.attempts.present ? data.attempts.value : this.attempts,
      nextAttempt: data.nextAttempt.present
          ? data.nextAttempt.value
          : this.nextAttempt,
      createdAt: data.createdAt.present ? data.createdAt.value : this.createdAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('WakefulnessQueueData(')
          ..write('id: $id, ')
          ..write('checkId: $checkId, ')
          ..write('code: $code, ')
          ..write('windowReference: $windowReference, ')
          ..write('respondedAt: $respondedAt, ')
          ..write('attempts: $attempts, ')
          ..write('nextAttempt: $nextAttempt, ')
          ..write('createdAt: $createdAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    checkId,
    code,
    windowReference,
    respondedAt,
    attempts,
    nextAttempt,
    createdAt,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is WakefulnessQueueData &&
          other.id == this.id &&
          other.checkId == this.checkId &&
          other.code == this.code &&
          other.windowReference == this.windowReference &&
          other.respondedAt == this.respondedAt &&
          other.attempts == this.attempts &&
          other.nextAttempt == this.nextAttempt &&
          other.createdAt == this.createdAt);
}

class WakefulnessQueueCompanion extends UpdateCompanion<WakefulnessQueueData> {
  final Value<int> id;
  final Value<String> checkId;
  final Value<String> code;
  final Value<int> windowReference;
  final Value<String> respondedAt;
  final Value<int> attempts;
  final Value<int> nextAttempt;
  final Value<int> createdAt;
  const WakefulnessQueueCompanion({
    this.id = const Value.absent(),
    this.checkId = const Value.absent(),
    this.code = const Value.absent(),
    this.windowReference = const Value.absent(),
    this.respondedAt = const Value.absent(),
    this.attempts = const Value.absent(),
    this.nextAttempt = const Value.absent(),
    this.createdAt = const Value.absent(),
  });
  WakefulnessQueueCompanion.insert({
    this.id = const Value.absent(),
    required String checkId,
    required String code,
    required int windowReference,
    required String respondedAt,
    this.attempts = const Value.absent(),
    this.nextAttempt = const Value.absent(),
    required int createdAt,
  }) : checkId = Value(checkId),
       code = Value(code),
       windowReference = Value(windowReference),
       respondedAt = Value(respondedAt),
       createdAt = Value(createdAt);
  static Insertable<WakefulnessQueueData> custom({
    Expression<int>? id,
    Expression<String>? checkId,
    Expression<String>? code,
    Expression<int>? windowReference,
    Expression<String>? respondedAt,
    Expression<int>? attempts,
    Expression<int>? nextAttempt,
    Expression<int>? createdAt,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (checkId != null) 'check_id': checkId,
      if (code != null) 'code': code,
      if (windowReference != null) 'window_reference': windowReference,
      if (respondedAt != null) 'responded_at': respondedAt,
      if (attempts != null) 'attempts': attempts,
      if (nextAttempt != null) 'next_attempt': nextAttempt,
      if (createdAt != null) 'created_at': createdAt,
    });
  }

  WakefulnessQueueCompanion copyWith({
    Value<int>? id,
    Value<String>? checkId,
    Value<String>? code,
    Value<int>? windowReference,
    Value<String>? respondedAt,
    Value<int>? attempts,
    Value<int>? nextAttempt,
    Value<int>? createdAt,
  }) {
    return WakefulnessQueueCompanion(
      id: id ?? this.id,
      checkId: checkId ?? this.checkId,
      code: code ?? this.code,
      windowReference: windowReference ?? this.windowReference,
      respondedAt: respondedAt ?? this.respondedAt,
      attempts: attempts ?? this.attempts,
      nextAttempt: nextAttempt ?? this.nextAttempt,
      createdAt: createdAt ?? this.createdAt,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<int>(id.value);
    }
    if (checkId.present) {
      map['check_id'] = Variable<String>(checkId.value);
    }
    if (code.present) {
      map['code'] = Variable<String>(code.value);
    }
    if (windowReference.present) {
      map['window_reference'] = Variable<int>(windowReference.value);
    }
    if (respondedAt.present) {
      map['responded_at'] = Variable<String>(respondedAt.value);
    }
    if (attempts.present) {
      map['attempts'] = Variable<int>(attempts.value);
    }
    if (nextAttempt.present) {
      map['next_attempt'] = Variable<int>(nextAttempt.value);
    }
    if (createdAt.present) {
      map['created_at'] = Variable<int>(createdAt.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('WakefulnessQueueCompanion(')
          ..write('id: $id, ')
          ..write('checkId: $checkId, ')
          ..write('code: $code, ')
          ..write('windowReference: $windowReference, ')
          ..write('respondedAt: $respondedAt, ')
          ..write('attempts: $attempts, ')
          ..write('nextAttempt: $nextAttempt, ')
          ..write('createdAt: $createdAt')
          ..write(')'))
        .toString();
  }
}

class $PhotoQueueTable extends PhotoQueue
    with TableInfo<$PhotoQueueTable, PhotoQueueData> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $PhotoQueueTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _idMeta = const VerificationMeta('id');
  @override
  late final GeneratedColumn<int> id = GeneratedColumn<int>(
    'id',
    aliasedName,
    false,
    hasAutoIncrement: true,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'PRIMARY KEY AUTOINCREMENT',
    ),
  );
  static const VerificationMeta _shiftIdMeta = const VerificationMeta(
    'shiftId',
  );
  @override
  late final GeneratedColumn<String> shiftId = GeneratedColumn<String>(
    'shift_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _nonceValueMeta = const VerificationMeta(
    'nonceValue',
  );
  @override
  late final GeneratedColumn<String> nonceValue = GeneratedColumn<String>(
    'nonce_value',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _filePathsMeta = const VerificationMeta(
    'filePaths',
  );
  @override
  late final GeneratedColumn<String> filePaths = GeneratedColumn<String>(
    'file_paths',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _signaturesMeta = const VerificationMeta(
    'signatures',
  );
  @override
  late final GeneratedColumn<String> signatures = GeneratedColumn<String>(
    'signatures',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _ntpReferenceMeta = const VerificationMeta(
    'ntpReference',
  );
  @override
  late final GeneratedColumn<String> ntpReference = GeneratedColumn<String>(
    'ntp_reference',
    aliasedName,
    true,
    type: DriftSqlType.string,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _elapsedSecondsMeta = const VerificationMeta(
    'elapsedSeconds',
  );
  @override
  late final GeneratedColumn<double> elapsedSeconds = GeneratedColumn<double>(
    'elapsed_seconds',
    aliasedName,
    false,
    type: DriftSqlType.double,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _latitudeMeta = const VerificationMeta(
    'latitude',
  );
  @override
  late final GeneratedColumn<double> latitude = GeneratedColumn<double>(
    'latitude',
    aliasedName,
    true,
    type: DriftSqlType.double,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _longitudeMeta = const VerificationMeta(
    'longitude',
  );
  @override
  late final GeneratedColumn<double> longitude = GeneratedColumn<double>(
    'longitude',
    aliasedName,
    true,
    type: DriftSqlType.double,
    requiredDuringInsert: false,
  );
  static const VerificationMeta _capturedAtMeta = const VerificationMeta(
    'capturedAt',
  );
  @override
  late final GeneratedColumn<String> capturedAt = GeneratedColumn<String>(
    'captured_at',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _attemptsMeta = const VerificationMeta(
    'attempts',
  );
  @override
  late final GeneratedColumn<int> attempts = GeneratedColumn<int>(
    'attempts',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _nextAttemptMeta = const VerificationMeta(
    'nextAttempt',
  );
  @override
  late final GeneratedColumn<int> nextAttempt = GeneratedColumn<int>(
    'next_attempt',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: false,
    defaultValue: const Constant(0),
  );
  static const VerificationMeta _createdAtMeta = const VerificationMeta(
    'createdAt',
  );
  @override
  late final GeneratedColumn<int> createdAt = GeneratedColumn<int>(
    'created_at',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  @override
  List<GeneratedColumn> get $columns => [
    id,
    shiftId,
    nonceValue,
    filePaths,
    signatures,
    ntpReference,
    elapsedSeconds,
    latitude,
    longitude,
    capturedAt,
    attempts,
    nextAttempt,
    createdAt,
  ];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'photo_queue';
  @override
  VerificationContext validateIntegrity(
    Insertable<PhotoQueueData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('id')) {
      context.handle(_idMeta, id.isAcceptableOrUnknown(data['id']!, _idMeta));
    }
    if (data.containsKey('shift_id')) {
      context.handle(
        _shiftIdMeta,
        shiftId.isAcceptableOrUnknown(data['shift_id']!, _shiftIdMeta),
      );
    } else if (isInserting) {
      context.missing(_shiftIdMeta);
    }
    if (data.containsKey('nonce_value')) {
      context.handle(
        _nonceValueMeta,
        nonceValue.isAcceptableOrUnknown(data['nonce_value']!, _nonceValueMeta),
      );
    } else if (isInserting) {
      context.missing(_nonceValueMeta);
    }
    if (data.containsKey('file_paths')) {
      context.handle(
        _filePathsMeta,
        filePaths.isAcceptableOrUnknown(data['file_paths']!, _filePathsMeta),
      );
    } else if (isInserting) {
      context.missing(_filePathsMeta);
    }
    if (data.containsKey('signatures')) {
      context.handle(
        _signaturesMeta,
        signatures.isAcceptableOrUnknown(data['signatures']!, _signaturesMeta),
      );
    } else if (isInserting) {
      context.missing(_signaturesMeta);
    }
    if (data.containsKey('ntp_reference')) {
      context.handle(
        _ntpReferenceMeta,
        ntpReference.isAcceptableOrUnknown(
          data['ntp_reference']!,
          _ntpReferenceMeta,
        ),
      );
    }
    if (data.containsKey('elapsed_seconds')) {
      context.handle(
        _elapsedSecondsMeta,
        elapsedSeconds.isAcceptableOrUnknown(
          data['elapsed_seconds']!,
          _elapsedSecondsMeta,
        ),
      );
    } else if (isInserting) {
      context.missing(_elapsedSecondsMeta);
    }
    if (data.containsKey('latitude')) {
      context.handle(
        _latitudeMeta,
        latitude.isAcceptableOrUnknown(data['latitude']!, _latitudeMeta),
      );
    }
    if (data.containsKey('longitude')) {
      context.handle(
        _longitudeMeta,
        longitude.isAcceptableOrUnknown(data['longitude']!, _longitudeMeta),
      );
    }
    if (data.containsKey('captured_at')) {
      context.handle(
        _capturedAtMeta,
        capturedAt.isAcceptableOrUnknown(data['captured_at']!, _capturedAtMeta),
      );
    } else if (isInserting) {
      context.missing(_capturedAtMeta);
    }
    if (data.containsKey('attempts')) {
      context.handle(
        _attemptsMeta,
        attempts.isAcceptableOrUnknown(data['attempts']!, _attemptsMeta),
      );
    }
    if (data.containsKey('next_attempt')) {
      context.handle(
        _nextAttemptMeta,
        nextAttempt.isAcceptableOrUnknown(
          data['next_attempt']!,
          _nextAttemptMeta,
        ),
      );
    }
    if (data.containsKey('created_at')) {
      context.handle(
        _createdAtMeta,
        createdAt.isAcceptableOrUnknown(data['created_at']!, _createdAtMeta),
      );
    } else if (isInserting) {
      context.missing(_createdAtMeta);
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {id};
  @override
  PhotoQueueData map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return PhotoQueueData(
      id: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}id'],
      )!,
      shiftId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}shift_id'],
      )!,
      nonceValue: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}nonce_value'],
      )!,
      filePaths: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}file_paths'],
      )!,
      signatures: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}signatures'],
      )!,
      ntpReference: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}ntp_reference'],
      ),
      elapsedSeconds: attachedDatabase.typeMapping.read(
        DriftSqlType.double,
        data['${effectivePrefix}elapsed_seconds'],
      )!,
      latitude: attachedDatabase.typeMapping.read(
        DriftSqlType.double,
        data['${effectivePrefix}latitude'],
      ),
      longitude: attachedDatabase.typeMapping.read(
        DriftSqlType.double,
        data['${effectivePrefix}longitude'],
      ),
      capturedAt: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}captured_at'],
      )!,
      attempts: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}attempts'],
      )!,
      nextAttempt: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}next_attempt'],
      )!,
      createdAt: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}created_at'],
      )!,
    );
  }

  @override
  $PhotoQueueTable createAlias(String alias) {
    return $PhotoQueueTable(attachedDatabase, alias);
  }
}

class PhotoQueueData extends DataClass implements Insertable<PhotoQueueData> {
  final int id;
  final String shiftId;
  final String nonceValue;
  final String filePaths;
  final String signatures;
  final String? ntpReference;
  final double elapsedSeconds;
  final double? latitude;
  final double? longitude;
  final String capturedAt;
  final int attempts;
  final int nextAttempt;
  final int createdAt;
  const PhotoQueueData({
    required this.id,
    required this.shiftId,
    required this.nonceValue,
    required this.filePaths,
    required this.signatures,
    this.ntpReference,
    required this.elapsedSeconds,
    this.latitude,
    this.longitude,
    required this.capturedAt,
    required this.attempts,
    required this.nextAttempt,
    required this.createdAt,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['id'] = Variable<int>(id);
    map['shift_id'] = Variable<String>(shiftId);
    map['nonce_value'] = Variable<String>(nonceValue);
    map['file_paths'] = Variable<String>(filePaths);
    map['signatures'] = Variable<String>(signatures);
    if (!nullToAbsent || ntpReference != null) {
      map['ntp_reference'] = Variable<String>(ntpReference);
    }
    map['elapsed_seconds'] = Variable<double>(elapsedSeconds);
    if (!nullToAbsent || latitude != null) {
      map['latitude'] = Variable<double>(latitude);
    }
    if (!nullToAbsent || longitude != null) {
      map['longitude'] = Variable<double>(longitude);
    }
    map['captured_at'] = Variable<String>(capturedAt);
    map['attempts'] = Variable<int>(attempts);
    map['next_attempt'] = Variable<int>(nextAttempt);
    map['created_at'] = Variable<int>(createdAt);
    return map;
  }

  PhotoQueueCompanion toCompanion(bool nullToAbsent) {
    return PhotoQueueCompanion(
      id: Value(id),
      shiftId: Value(shiftId),
      nonceValue: Value(nonceValue),
      filePaths: Value(filePaths),
      signatures: Value(signatures),
      ntpReference: ntpReference == null && nullToAbsent
          ? const Value.absent()
          : Value(ntpReference),
      elapsedSeconds: Value(elapsedSeconds),
      latitude: latitude == null && nullToAbsent
          ? const Value.absent()
          : Value(latitude),
      longitude: longitude == null && nullToAbsent
          ? const Value.absent()
          : Value(longitude),
      capturedAt: Value(capturedAt),
      attempts: Value(attempts),
      nextAttempt: Value(nextAttempt),
      createdAt: Value(createdAt),
    );
  }

  factory PhotoQueueData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return PhotoQueueData(
      id: serializer.fromJson<int>(json['id']),
      shiftId: serializer.fromJson<String>(json['shiftId']),
      nonceValue: serializer.fromJson<String>(json['nonceValue']),
      filePaths: serializer.fromJson<String>(json['filePaths']),
      signatures: serializer.fromJson<String>(json['signatures']),
      ntpReference: serializer.fromJson<String?>(json['ntpReference']),
      elapsedSeconds: serializer.fromJson<double>(json['elapsedSeconds']),
      latitude: serializer.fromJson<double?>(json['latitude']),
      longitude: serializer.fromJson<double?>(json['longitude']),
      capturedAt: serializer.fromJson<String>(json['capturedAt']),
      attempts: serializer.fromJson<int>(json['attempts']),
      nextAttempt: serializer.fromJson<int>(json['nextAttempt']),
      createdAt: serializer.fromJson<int>(json['createdAt']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'id': serializer.toJson<int>(id),
      'shiftId': serializer.toJson<String>(shiftId),
      'nonceValue': serializer.toJson<String>(nonceValue),
      'filePaths': serializer.toJson<String>(filePaths),
      'signatures': serializer.toJson<String>(signatures),
      'ntpReference': serializer.toJson<String?>(ntpReference),
      'elapsedSeconds': serializer.toJson<double>(elapsedSeconds),
      'latitude': serializer.toJson<double?>(latitude),
      'longitude': serializer.toJson<double?>(longitude),
      'capturedAt': serializer.toJson<String>(capturedAt),
      'attempts': serializer.toJson<int>(attempts),
      'nextAttempt': serializer.toJson<int>(nextAttempt),
      'createdAt': serializer.toJson<int>(createdAt),
    };
  }

  PhotoQueueData copyWith({
    int? id,
    String? shiftId,
    String? nonceValue,
    String? filePaths,
    String? signatures,
    Value<String?> ntpReference = const Value.absent(),
    double? elapsedSeconds,
    Value<double?> latitude = const Value.absent(),
    Value<double?> longitude = const Value.absent(),
    String? capturedAt,
    int? attempts,
    int? nextAttempt,
    int? createdAt,
  }) => PhotoQueueData(
    id: id ?? this.id,
    shiftId: shiftId ?? this.shiftId,
    nonceValue: nonceValue ?? this.nonceValue,
    filePaths: filePaths ?? this.filePaths,
    signatures: signatures ?? this.signatures,
    ntpReference: ntpReference.present ? ntpReference.value : this.ntpReference,
    elapsedSeconds: elapsedSeconds ?? this.elapsedSeconds,
    latitude: latitude.present ? latitude.value : this.latitude,
    longitude: longitude.present ? longitude.value : this.longitude,
    capturedAt: capturedAt ?? this.capturedAt,
    attempts: attempts ?? this.attempts,
    nextAttempt: nextAttempt ?? this.nextAttempt,
    createdAt: createdAt ?? this.createdAt,
  );
  PhotoQueueData copyWithCompanion(PhotoQueueCompanion data) {
    return PhotoQueueData(
      id: data.id.present ? data.id.value : this.id,
      shiftId: data.shiftId.present ? data.shiftId.value : this.shiftId,
      nonceValue: data.nonceValue.present
          ? data.nonceValue.value
          : this.nonceValue,
      filePaths: data.filePaths.present ? data.filePaths.value : this.filePaths,
      signatures: data.signatures.present
          ? data.signatures.value
          : this.signatures,
      ntpReference: data.ntpReference.present
          ? data.ntpReference.value
          : this.ntpReference,
      elapsedSeconds: data.elapsedSeconds.present
          ? data.elapsedSeconds.value
          : this.elapsedSeconds,
      latitude: data.latitude.present ? data.latitude.value : this.latitude,
      longitude: data.longitude.present ? data.longitude.value : this.longitude,
      capturedAt: data.capturedAt.present
          ? data.capturedAt.value
          : this.capturedAt,
      attempts: data.attempts.present ? data.attempts.value : this.attempts,
      nextAttempt: data.nextAttempt.present
          ? data.nextAttempt.value
          : this.nextAttempt,
      createdAt: data.createdAt.present ? data.createdAt.value : this.createdAt,
    );
  }

  @override
  String toString() {
    return (StringBuffer('PhotoQueueData(')
          ..write('id: $id, ')
          ..write('shiftId: $shiftId, ')
          ..write('nonceValue: $nonceValue, ')
          ..write('filePaths: $filePaths, ')
          ..write('signatures: $signatures, ')
          ..write('ntpReference: $ntpReference, ')
          ..write('elapsedSeconds: $elapsedSeconds, ')
          ..write('latitude: $latitude, ')
          ..write('longitude: $longitude, ')
          ..write('capturedAt: $capturedAt, ')
          ..write('attempts: $attempts, ')
          ..write('nextAttempt: $nextAttempt, ')
          ..write('createdAt: $createdAt')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(
    id,
    shiftId,
    nonceValue,
    filePaths,
    signatures,
    ntpReference,
    elapsedSeconds,
    latitude,
    longitude,
    capturedAt,
    attempts,
    nextAttempt,
    createdAt,
  );
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is PhotoQueueData &&
          other.id == this.id &&
          other.shiftId == this.shiftId &&
          other.nonceValue == this.nonceValue &&
          other.filePaths == this.filePaths &&
          other.signatures == this.signatures &&
          other.ntpReference == this.ntpReference &&
          other.elapsedSeconds == this.elapsedSeconds &&
          other.latitude == this.latitude &&
          other.longitude == this.longitude &&
          other.capturedAt == this.capturedAt &&
          other.attempts == this.attempts &&
          other.nextAttempt == this.nextAttempt &&
          other.createdAt == this.createdAt);
}

class PhotoQueueCompanion extends UpdateCompanion<PhotoQueueData> {
  final Value<int> id;
  final Value<String> shiftId;
  final Value<String> nonceValue;
  final Value<String> filePaths;
  final Value<String> signatures;
  final Value<String?> ntpReference;
  final Value<double> elapsedSeconds;
  final Value<double?> latitude;
  final Value<double?> longitude;
  final Value<String> capturedAt;
  final Value<int> attempts;
  final Value<int> nextAttempt;
  final Value<int> createdAt;
  const PhotoQueueCompanion({
    this.id = const Value.absent(),
    this.shiftId = const Value.absent(),
    this.nonceValue = const Value.absent(),
    this.filePaths = const Value.absent(),
    this.signatures = const Value.absent(),
    this.ntpReference = const Value.absent(),
    this.elapsedSeconds = const Value.absent(),
    this.latitude = const Value.absent(),
    this.longitude = const Value.absent(),
    this.capturedAt = const Value.absent(),
    this.attempts = const Value.absent(),
    this.nextAttempt = const Value.absent(),
    this.createdAt = const Value.absent(),
  });
  PhotoQueueCompanion.insert({
    this.id = const Value.absent(),
    required String shiftId,
    required String nonceValue,
    required String filePaths,
    required String signatures,
    this.ntpReference = const Value.absent(),
    required double elapsedSeconds,
    this.latitude = const Value.absent(),
    this.longitude = const Value.absent(),
    required String capturedAt,
    this.attempts = const Value.absent(),
    this.nextAttempt = const Value.absent(),
    required int createdAt,
  }) : shiftId = Value(shiftId),
       nonceValue = Value(nonceValue),
       filePaths = Value(filePaths),
       signatures = Value(signatures),
       elapsedSeconds = Value(elapsedSeconds),
       capturedAt = Value(capturedAt),
       createdAt = Value(createdAt);
  static Insertable<PhotoQueueData> custom({
    Expression<int>? id,
    Expression<String>? shiftId,
    Expression<String>? nonceValue,
    Expression<String>? filePaths,
    Expression<String>? signatures,
    Expression<String>? ntpReference,
    Expression<double>? elapsedSeconds,
    Expression<double>? latitude,
    Expression<double>? longitude,
    Expression<String>? capturedAt,
    Expression<int>? attempts,
    Expression<int>? nextAttempt,
    Expression<int>? createdAt,
  }) {
    return RawValuesInsertable({
      if (id != null) 'id': id,
      if (shiftId != null) 'shift_id': shiftId,
      if (nonceValue != null) 'nonce_value': nonceValue,
      if (filePaths != null) 'file_paths': filePaths,
      if (signatures != null) 'signatures': signatures,
      if (ntpReference != null) 'ntp_reference': ntpReference,
      if (elapsedSeconds != null) 'elapsed_seconds': elapsedSeconds,
      if (latitude != null) 'latitude': latitude,
      if (longitude != null) 'longitude': longitude,
      if (capturedAt != null) 'captured_at': capturedAt,
      if (attempts != null) 'attempts': attempts,
      if (nextAttempt != null) 'next_attempt': nextAttempt,
      if (createdAt != null) 'created_at': createdAt,
    });
  }

  PhotoQueueCompanion copyWith({
    Value<int>? id,
    Value<String>? shiftId,
    Value<String>? nonceValue,
    Value<String>? filePaths,
    Value<String>? signatures,
    Value<String?>? ntpReference,
    Value<double>? elapsedSeconds,
    Value<double?>? latitude,
    Value<double?>? longitude,
    Value<String>? capturedAt,
    Value<int>? attempts,
    Value<int>? nextAttempt,
    Value<int>? createdAt,
  }) {
    return PhotoQueueCompanion(
      id: id ?? this.id,
      shiftId: shiftId ?? this.shiftId,
      nonceValue: nonceValue ?? this.nonceValue,
      filePaths: filePaths ?? this.filePaths,
      signatures: signatures ?? this.signatures,
      ntpReference: ntpReference ?? this.ntpReference,
      elapsedSeconds: elapsedSeconds ?? this.elapsedSeconds,
      latitude: latitude ?? this.latitude,
      longitude: longitude ?? this.longitude,
      capturedAt: capturedAt ?? this.capturedAt,
      attempts: attempts ?? this.attempts,
      nextAttempt: nextAttempt ?? this.nextAttempt,
      createdAt: createdAt ?? this.createdAt,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (id.present) {
      map['id'] = Variable<int>(id.value);
    }
    if (shiftId.present) {
      map['shift_id'] = Variable<String>(shiftId.value);
    }
    if (nonceValue.present) {
      map['nonce_value'] = Variable<String>(nonceValue.value);
    }
    if (filePaths.present) {
      map['file_paths'] = Variable<String>(filePaths.value);
    }
    if (signatures.present) {
      map['signatures'] = Variable<String>(signatures.value);
    }
    if (ntpReference.present) {
      map['ntp_reference'] = Variable<String>(ntpReference.value);
    }
    if (elapsedSeconds.present) {
      map['elapsed_seconds'] = Variable<double>(elapsedSeconds.value);
    }
    if (latitude.present) {
      map['latitude'] = Variable<double>(latitude.value);
    }
    if (longitude.present) {
      map['longitude'] = Variable<double>(longitude.value);
    }
    if (capturedAt.present) {
      map['captured_at'] = Variable<String>(capturedAt.value);
    }
    if (attempts.present) {
      map['attempts'] = Variable<int>(attempts.value);
    }
    if (nextAttempt.present) {
      map['next_attempt'] = Variable<int>(nextAttempt.value);
    }
    if (createdAt.present) {
      map['created_at'] = Variable<int>(createdAt.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('PhotoQueueCompanion(')
          ..write('id: $id, ')
          ..write('shiftId: $shiftId, ')
          ..write('nonceValue: $nonceValue, ')
          ..write('filePaths: $filePaths, ')
          ..write('signatures: $signatures, ')
          ..write('ntpReference: $ntpReference, ')
          ..write('elapsedSeconds: $elapsedSeconds, ')
          ..write('latitude: $latitude, ')
          ..write('longitude: $longitude, ')
          ..write('capturedAt: $capturedAt, ')
          ..write('attempts: $attempts, ')
          ..write('nextAttempt: $nextAttempt, ')
          ..write('createdAt: $createdAt')
          ..write(')'))
        .toString();
  }
}

class $NoncePoolTable extends NoncePool
    with TableInfo<$NoncePoolTable, NoncePoolData> {
  @override
  final GeneratedDatabase attachedDatabase;
  final String? _alias;
  $NoncePoolTable(this.attachedDatabase, [this._alias]);
  static const VerificationMeta _nonceValueMeta = const VerificationMeta(
    'nonceValue',
  );
  @override
  late final GeneratedColumn<String> nonceValue = GeneratedColumn<String>(
    'nonce_value',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _shiftIdMeta = const VerificationMeta(
    'shiftId',
  );
  @override
  late final GeneratedColumn<String> shiftId = GeneratedColumn<String>(
    'shift_id',
    aliasedName,
    false,
    type: DriftSqlType.string,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _expiresAtMeta = const VerificationMeta(
    'expiresAt',
  );
  @override
  late final GeneratedColumn<int> expiresAt = GeneratedColumn<int>(
    'expires_at',
    aliasedName,
    false,
    type: DriftSqlType.int,
    requiredDuringInsert: true,
  );
  static const VerificationMeta _drawnMeta = const VerificationMeta('drawn');
  @override
  late final GeneratedColumn<bool> drawn = GeneratedColumn<bool>(
    'drawn',
    aliasedName,
    false,
    type: DriftSqlType.bool,
    requiredDuringInsert: false,
    defaultConstraints: GeneratedColumn.constraintIsAlways(
      'CHECK ("drawn" IN (0, 1))',
    ),
    defaultValue: const Constant(false),
  );
  @override
  List<GeneratedColumn> get $columns => [nonceValue, shiftId, expiresAt, drawn];
  @override
  String get aliasedName => _alias ?? actualTableName;
  @override
  String get actualTableName => $name;
  static const String $name = 'nonce_pool';
  @override
  VerificationContext validateIntegrity(
    Insertable<NoncePoolData> instance, {
    bool isInserting = false,
  }) {
    final context = VerificationContext();
    final data = instance.toColumns(true);
    if (data.containsKey('nonce_value')) {
      context.handle(
        _nonceValueMeta,
        nonceValue.isAcceptableOrUnknown(data['nonce_value']!, _nonceValueMeta),
      );
    } else if (isInserting) {
      context.missing(_nonceValueMeta);
    }
    if (data.containsKey('shift_id')) {
      context.handle(
        _shiftIdMeta,
        shiftId.isAcceptableOrUnknown(data['shift_id']!, _shiftIdMeta),
      );
    } else if (isInserting) {
      context.missing(_shiftIdMeta);
    }
    if (data.containsKey('expires_at')) {
      context.handle(
        _expiresAtMeta,
        expiresAt.isAcceptableOrUnknown(data['expires_at']!, _expiresAtMeta),
      );
    } else if (isInserting) {
      context.missing(_expiresAtMeta);
    }
    if (data.containsKey('drawn')) {
      context.handle(
        _drawnMeta,
        drawn.isAcceptableOrUnknown(data['drawn']!, _drawnMeta),
      );
    }
    return context;
  }

  @override
  Set<GeneratedColumn> get $primaryKey => {nonceValue};
  @override
  NoncePoolData map(Map<String, dynamic> data, {String? tablePrefix}) {
    final effectivePrefix = tablePrefix != null ? '$tablePrefix.' : '';
    return NoncePoolData(
      nonceValue: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}nonce_value'],
      )!,
      shiftId: attachedDatabase.typeMapping.read(
        DriftSqlType.string,
        data['${effectivePrefix}shift_id'],
      )!,
      expiresAt: attachedDatabase.typeMapping.read(
        DriftSqlType.int,
        data['${effectivePrefix}expires_at'],
      )!,
      drawn: attachedDatabase.typeMapping.read(
        DriftSqlType.bool,
        data['${effectivePrefix}drawn'],
      )!,
    );
  }

  @override
  $NoncePoolTable createAlias(String alias) {
    return $NoncePoolTable(attachedDatabase, alias);
  }
}

class NoncePoolData extends DataClass implements Insertable<NoncePoolData> {
  final String nonceValue;
  final String shiftId;
  final int expiresAt;
  final bool drawn;
  const NoncePoolData({
    required this.nonceValue,
    required this.shiftId,
    required this.expiresAt,
    required this.drawn,
  });
  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    map['nonce_value'] = Variable<String>(nonceValue);
    map['shift_id'] = Variable<String>(shiftId);
    map['expires_at'] = Variable<int>(expiresAt);
    map['drawn'] = Variable<bool>(drawn);
    return map;
  }

  NoncePoolCompanion toCompanion(bool nullToAbsent) {
    return NoncePoolCompanion(
      nonceValue: Value(nonceValue),
      shiftId: Value(shiftId),
      expiresAt: Value(expiresAt),
      drawn: Value(drawn),
    );
  }

  factory NoncePoolData.fromJson(
    Map<String, dynamic> json, {
    ValueSerializer? serializer,
  }) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return NoncePoolData(
      nonceValue: serializer.fromJson<String>(json['nonceValue']),
      shiftId: serializer.fromJson<String>(json['shiftId']),
      expiresAt: serializer.fromJson<int>(json['expiresAt']),
      drawn: serializer.fromJson<bool>(json['drawn']),
    );
  }
  @override
  Map<String, dynamic> toJson({ValueSerializer? serializer}) {
    serializer ??= driftRuntimeOptions.defaultSerializer;
    return <String, dynamic>{
      'nonceValue': serializer.toJson<String>(nonceValue),
      'shiftId': serializer.toJson<String>(shiftId),
      'expiresAt': serializer.toJson<int>(expiresAt),
      'drawn': serializer.toJson<bool>(drawn),
    };
  }

  NoncePoolData copyWith({
    String? nonceValue,
    String? shiftId,
    int? expiresAt,
    bool? drawn,
  }) => NoncePoolData(
    nonceValue: nonceValue ?? this.nonceValue,
    shiftId: shiftId ?? this.shiftId,
    expiresAt: expiresAt ?? this.expiresAt,
    drawn: drawn ?? this.drawn,
  );
  NoncePoolData copyWithCompanion(NoncePoolCompanion data) {
    return NoncePoolData(
      nonceValue: data.nonceValue.present
          ? data.nonceValue.value
          : this.nonceValue,
      shiftId: data.shiftId.present ? data.shiftId.value : this.shiftId,
      expiresAt: data.expiresAt.present ? data.expiresAt.value : this.expiresAt,
      drawn: data.drawn.present ? data.drawn.value : this.drawn,
    );
  }

  @override
  String toString() {
    return (StringBuffer('NoncePoolData(')
          ..write('nonceValue: $nonceValue, ')
          ..write('shiftId: $shiftId, ')
          ..write('expiresAt: $expiresAt, ')
          ..write('drawn: $drawn')
          ..write(')'))
        .toString();
  }

  @override
  int get hashCode => Object.hash(nonceValue, shiftId, expiresAt, drawn);
  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      (other is NoncePoolData &&
          other.nonceValue == this.nonceValue &&
          other.shiftId == this.shiftId &&
          other.expiresAt == this.expiresAt &&
          other.drawn == this.drawn);
}

class NoncePoolCompanion extends UpdateCompanion<NoncePoolData> {
  final Value<String> nonceValue;
  final Value<String> shiftId;
  final Value<int> expiresAt;
  final Value<bool> drawn;
  final Value<int> rowid;
  const NoncePoolCompanion({
    this.nonceValue = const Value.absent(),
    this.shiftId = const Value.absent(),
    this.expiresAt = const Value.absent(),
    this.drawn = const Value.absent(),
    this.rowid = const Value.absent(),
  });
  NoncePoolCompanion.insert({
    required String nonceValue,
    required String shiftId,
    required int expiresAt,
    this.drawn = const Value.absent(),
    this.rowid = const Value.absent(),
  }) : nonceValue = Value(nonceValue),
       shiftId = Value(shiftId),
       expiresAt = Value(expiresAt);
  static Insertable<NoncePoolData> custom({
    Expression<String>? nonceValue,
    Expression<String>? shiftId,
    Expression<int>? expiresAt,
    Expression<bool>? drawn,
    Expression<int>? rowid,
  }) {
    return RawValuesInsertable({
      if (nonceValue != null) 'nonce_value': nonceValue,
      if (shiftId != null) 'shift_id': shiftId,
      if (expiresAt != null) 'expires_at': expiresAt,
      if (drawn != null) 'drawn': drawn,
      if (rowid != null) 'rowid': rowid,
    });
  }

  NoncePoolCompanion copyWith({
    Value<String>? nonceValue,
    Value<String>? shiftId,
    Value<int>? expiresAt,
    Value<bool>? drawn,
    Value<int>? rowid,
  }) {
    return NoncePoolCompanion(
      nonceValue: nonceValue ?? this.nonceValue,
      shiftId: shiftId ?? this.shiftId,
      expiresAt: expiresAt ?? this.expiresAt,
      drawn: drawn ?? this.drawn,
      rowid: rowid ?? this.rowid,
    );
  }

  @override
  Map<String, Expression> toColumns(bool nullToAbsent) {
    final map = <String, Expression>{};
    if (nonceValue.present) {
      map['nonce_value'] = Variable<String>(nonceValue.value);
    }
    if (shiftId.present) {
      map['shift_id'] = Variable<String>(shiftId.value);
    }
    if (expiresAt.present) {
      map['expires_at'] = Variable<int>(expiresAt.value);
    }
    if (drawn.present) {
      map['drawn'] = Variable<bool>(drawn.value);
    }
    if (rowid.present) {
      map['rowid'] = Variable<int>(rowid.value);
    }
    return map;
  }

  @override
  String toString() {
    return (StringBuffer('NoncePoolCompanion(')
          ..write('nonceValue: $nonceValue, ')
          ..write('shiftId: $shiftId, ')
          ..write('expiresAt: $expiresAt, ')
          ..write('drawn: $drawn, ')
          ..write('rowid: $rowid')
          ..write(')'))
        .toString();
  }
}

abstract class _$OfflineQueueDb extends GeneratedDatabase {
  _$OfflineQueueDb(QueryExecutor e) : super(e);
  $OfflineQueueDbManager get managers => $OfflineQueueDbManager(this);
  late final $GpsQueueTable gpsQueue = $GpsQueueTable(this);
  late final $WakefulnessQueueTable wakefulnessQueue = $WakefulnessQueueTable(
    this,
  );
  late final $PhotoQueueTable photoQueue = $PhotoQueueTable(this);
  late final $NoncePoolTable noncePool = $NoncePoolTable(this);
  @override
  Iterable<TableInfo<Table, Object?>> get allTables =>
      allSchemaEntities.whereType<TableInfo<Table, Object?>>();
  @override
  List<DatabaseSchemaEntity> get allSchemaEntities => [
    gpsQueue,
    wakefulnessQueue,
    photoQueue,
    noncePool,
  ];
}

typedef $$GpsQueueTableCreateCompanionBuilder =
    GpsQueueCompanion Function({
      Value<int> id,
      required String shiftId,
      required double latitude,
      required double longitude,
      Value<double?> accuracy,
      Value<double?> battery,
      required String recordedAt,
      Value<int> attempts,
      Value<int> nextAttempt,
      required int createdAt,
    });
typedef $$GpsQueueTableUpdateCompanionBuilder =
    GpsQueueCompanion Function({
      Value<int> id,
      Value<String> shiftId,
      Value<double> latitude,
      Value<double> longitude,
      Value<double?> accuracy,
      Value<double?> battery,
      Value<String> recordedAt,
      Value<int> attempts,
      Value<int> nextAttempt,
      Value<int> createdAt,
    });

class $$GpsQueueTableFilterComposer
    extends Composer<_$OfflineQueueDb, $GpsQueueTable> {
  $$GpsQueueTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get shiftId => $composableBuilder(
    column: $table.shiftId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<double> get latitude => $composableBuilder(
    column: $table.latitude,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<double> get longitude => $composableBuilder(
    column: $table.longitude,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<double> get accuracy => $composableBuilder(
    column: $table.accuracy,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<double> get battery => $composableBuilder(
    column: $table.battery,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get recordedAt => $composableBuilder(
    column: $table.recordedAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get attempts => $composableBuilder(
    column: $table.attempts,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get nextAttempt => $composableBuilder(
    column: $table.nextAttempt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$GpsQueueTableOrderingComposer
    extends Composer<_$OfflineQueueDb, $GpsQueueTable> {
  $$GpsQueueTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get shiftId => $composableBuilder(
    column: $table.shiftId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<double> get latitude => $composableBuilder(
    column: $table.latitude,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<double> get longitude => $composableBuilder(
    column: $table.longitude,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<double> get accuracy => $composableBuilder(
    column: $table.accuracy,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<double> get battery => $composableBuilder(
    column: $table.battery,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get recordedAt => $composableBuilder(
    column: $table.recordedAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get attempts => $composableBuilder(
    column: $table.attempts,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get nextAttempt => $composableBuilder(
    column: $table.nextAttempt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$GpsQueueTableAnnotationComposer
    extends Composer<_$OfflineQueueDb, $GpsQueueTable> {
  $$GpsQueueTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<int> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get shiftId =>
      $composableBuilder(column: $table.shiftId, builder: (column) => column);

  GeneratedColumn<double> get latitude =>
      $composableBuilder(column: $table.latitude, builder: (column) => column);

  GeneratedColumn<double> get longitude =>
      $composableBuilder(column: $table.longitude, builder: (column) => column);

  GeneratedColumn<double> get accuracy =>
      $composableBuilder(column: $table.accuracy, builder: (column) => column);

  GeneratedColumn<double> get battery =>
      $composableBuilder(column: $table.battery, builder: (column) => column);

  GeneratedColumn<String> get recordedAt => $composableBuilder(
    column: $table.recordedAt,
    builder: (column) => column,
  );

  GeneratedColumn<int> get attempts =>
      $composableBuilder(column: $table.attempts, builder: (column) => column);

  GeneratedColumn<int> get nextAttempt => $composableBuilder(
    column: $table.nextAttempt,
    builder: (column) => column,
  );

  GeneratedColumn<int> get createdAt =>
      $composableBuilder(column: $table.createdAt, builder: (column) => column);
}

class $$GpsQueueTableTableManager
    extends
        RootTableManager<
          _$OfflineQueueDb,
          $GpsQueueTable,
          GpsQueueData,
          $$GpsQueueTableFilterComposer,
          $$GpsQueueTableOrderingComposer,
          $$GpsQueueTableAnnotationComposer,
          $$GpsQueueTableCreateCompanionBuilder,
          $$GpsQueueTableUpdateCompanionBuilder,
          (
            GpsQueueData,
            BaseReferences<_$OfflineQueueDb, $GpsQueueTable, GpsQueueData>,
          ),
          GpsQueueData,
          PrefetchHooks Function()
        > {
  $$GpsQueueTableTableManager(_$OfflineQueueDb db, $GpsQueueTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$GpsQueueTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$GpsQueueTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$GpsQueueTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                Value<String> shiftId = const Value.absent(),
                Value<double> latitude = const Value.absent(),
                Value<double> longitude = const Value.absent(),
                Value<double?> accuracy = const Value.absent(),
                Value<double?> battery = const Value.absent(),
                Value<String> recordedAt = const Value.absent(),
                Value<int> attempts = const Value.absent(),
                Value<int> nextAttempt = const Value.absent(),
                Value<int> createdAt = const Value.absent(),
              }) => GpsQueueCompanion(
                id: id,
                shiftId: shiftId,
                latitude: latitude,
                longitude: longitude,
                accuracy: accuracy,
                battery: battery,
                recordedAt: recordedAt,
                attempts: attempts,
                nextAttempt: nextAttempt,
                createdAt: createdAt,
              ),
          createCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                required String shiftId,
                required double latitude,
                required double longitude,
                Value<double?> accuracy = const Value.absent(),
                Value<double?> battery = const Value.absent(),
                required String recordedAt,
                Value<int> attempts = const Value.absent(),
                Value<int> nextAttempt = const Value.absent(),
                required int createdAt,
              }) => GpsQueueCompanion.insert(
                id: id,
                shiftId: shiftId,
                latitude: latitude,
                longitude: longitude,
                accuracy: accuracy,
                battery: battery,
                recordedAt: recordedAt,
                attempts: attempts,
                nextAttempt: nextAttempt,
                createdAt: createdAt,
              ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$GpsQueueTableProcessedTableManager =
    ProcessedTableManager<
      _$OfflineQueueDb,
      $GpsQueueTable,
      GpsQueueData,
      $$GpsQueueTableFilterComposer,
      $$GpsQueueTableOrderingComposer,
      $$GpsQueueTableAnnotationComposer,
      $$GpsQueueTableCreateCompanionBuilder,
      $$GpsQueueTableUpdateCompanionBuilder,
      (
        GpsQueueData,
        BaseReferences<_$OfflineQueueDb, $GpsQueueTable, GpsQueueData>,
      ),
      GpsQueueData,
      PrefetchHooks Function()
    >;
typedef $$WakefulnessQueueTableCreateCompanionBuilder =
    WakefulnessQueueCompanion Function({
      Value<int> id,
      required String checkId,
      required String code,
      required int windowReference,
      required String respondedAt,
      Value<int> attempts,
      Value<int> nextAttempt,
      required int createdAt,
    });
typedef $$WakefulnessQueueTableUpdateCompanionBuilder =
    WakefulnessQueueCompanion Function({
      Value<int> id,
      Value<String> checkId,
      Value<String> code,
      Value<int> windowReference,
      Value<String> respondedAt,
      Value<int> attempts,
      Value<int> nextAttempt,
      Value<int> createdAt,
    });

class $$WakefulnessQueueTableFilterComposer
    extends Composer<_$OfflineQueueDb, $WakefulnessQueueTable> {
  $$WakefulnessQueueTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get checkId => $composableBuilder(
    column: $table.checkId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get code => $composableBuilder(
    column: $table.code,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get windowReference => $composableBuilder(
    column: $table.windowReference,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get respondedAt => $composableBuilder(
    column: $table.respondedAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get attempts => $composableBuilder(
    column: $table.attempts,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get nextAttempt => $composableBuilder(
    column: $table.nextAttempt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$WakefulnessQueueTableOrderingComposer
    extends Composer<_$OfflineQueueDb, $WakefulnessQueueTable> {
  $$WakefulnessQueueTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get checkId => $composableBuilder(
    column: $table.checkId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get code => $composableBuilder(
    column: $table.code,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get windowReference => $composableBuilder(
    column: $table.windowReference,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get respondedAt => $composableBuilder(
    column: $table.respondedAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get attempts => $composableBuilder(
    column: $table.attempts,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get nextAttempt => $composableBuilder(
    column: $table.nextAttempt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$WakefulnessQueueTableAnnotationComposer
    extends Composer<_$OfflineQueueDb, $WakefulnessQueueTable> {
  $$WakefulnessQueueTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<int> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get checkId =>
      $composableBuilder(column: $table.checkId, builder: (column) => column);

  GeneratedColumn<String> get code =>
      $composableBuilder(column: $table.code, builder: (column) => column);

  GeneratedColumn<int> get windowReference => $composableBuilder(
    column: $table.windowReference,
    builder: (column) => column,
  );

  GeneratedColumn<String> get respondedAt => $composableBuilder(
    column: $table.respondedAt,
    builder: (column) => column,
  );

  GeneratedColumn<int> get attempts =>
      $composableBuilder(column: $table.attempts, builder: (column) => column);

  GeneratedColumn<int> get nextAttempt => $composableBuilder(
    column: $table.nextAttempt,
    builder: (column) => column,
  );

  GeneratedColumn<int> get createdAt =>
      $composableBuilder(column: $table.createdAt, builder: (column) => column);
}

class $$WakefulnessQueueTableTableManager
    extends
        RootTableManager<
          _$OfflineQueueDb,
          $WakefulnessQueueTable,
          WakefulnessQueueData,
          $$WakefulnessQueueTableFilterComposer,
          $$WakefulnessQueueTableOrderingComposer,
          $$WakefulnessQueueTableAnnotationComposer,
          $$WakefulnessQueueTableCreateCompanionBuilder,
          $$WakefulnessQueueTableUpdateCompanionBuilder,
          (
            WakefulnessQueueData,
            BaseReferences<
              _$OfflineQueueDb,
              $WakefulnessQueueTable,
              WakefulnessQueueData
            >,
          ),
          WakefulnessQueueData,
          PrefetchHooks Function()
        > {
  $$WakefulnessQueueTableTableManager(
    _$OfflineQueueDb db,
    $WakefulnessQueueTable table,
  ) : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$WakefulnessQueueTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$WakefulnessQueueTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$WakefulnessQueueTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                Value<String> checkId = const Value.absent(),
                Value<String> code = const Value.absent(),
                Value<int> windowReference = const Value.absent(),
                Value<String> respondedAt = const Value.absent(),
                Value<int> attempts = const Value.absent(),
                Value<int> nextAttempt = const Value.absent(),
                Value<int> createdAt = const Value.absent(),
              }) => WakefulnessQueueCompanion(
                id: id,
                checkId: checkId,
                code: code,
                windowReference: windowReference,
                respondedAt: respondedAt,
                attempts: attempts,
                nextAttempt: nextAttempt,
                createdAt: createdAt,
              ),
          createCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                required String checkId,
                required String code,
                required int windowReference,
                required String respondedAt,
                Value<int> attempts = const Value.absent(),
                Value<int> nextAttempt = const Value.absent(),
                required int createdAt,
              }) => WakefulnessQueueCompanion.insert(
                id: id,
                checkId: checkId,
                code: code,
                windowReference: windowReference,
                respondedAt: respondedAt,
                attempts: attempts,
                nextAttempt: nextAttempt,
                createdAt: createdAt,
              ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$WakefulnessQueueTableProcessedTableManager =
    ProcessedTableManager<
      _$OfflineQueueDb,
      $WakefulnessQueueTable,
      WakefulnessQueueData,
      $$WakefulnessQueueTableFilterComposer,
      $$WakefulnessQueueTableOrderingComposer,
      $$WakefulnessQueueTableAnnotationComposer,
      $$WakefulnessQueueTableCreateCompanionBuilder,
      $$WakefulnessQueueTableUpdateCompanionBuilder,
      (
        WakefulnessQueueData,
        BaseReferences<
          _$OfflineQueueDb,
          $WakefulnessQueueTable,
          WakefulnessQueueData
        >,
      ),
      WakefulnessQueueData,
      PrefetchHooks Function()
    >;
typedef $$PhotoQueueTableCreateCompanionBuilder =
    PhotoQueueCompanion Function({
      Value<int> id,
      required String shiftId,
      required String nonceValue,
      required String filePaths,
      required String signatures,
      Value<String?> ntpReference,
      required double elapsedSeconds,
      Value<double?> latitude,
      Value<double?> longitude,
      required String capturedAt,
      Value<int> attempts,
      Value<int> nextAttempt,
      required int createdAt,
    });
typedef $$PhotoQueueTableUpdateCompanionBuilder =
    PhotoQueueCompanion Function({
      Value<int> id,
      Value<String> shiftId,
      Value<String> nonceValue,
      Value<String> filePaths,
      Value<String> signatures,
      Value<String?> ntpReference,
      Value<double> elapsedSeconds,
      Value<double?> latitude,
      Value<double?> longitude,
      Value<String> capturedAt,
      Value<int> attempts,
      Value<int> nextAttempt,
      Value<int> createdAt,
    });

class $$PhotoQueueTableFilterComposer
    extends Composer<_$OfflineQueueDb, $PhotoQueueTable> {
  $$PhotoQueueTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get shiftId => $composableBuilder(
    column: $table.shiftId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get nonceValue => $composableBuilder(
    column: $table.nonceValue,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get filePaths => $composableBuilder(
    column: $table.filePaths,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get signatures => $composableBuilder(
    column: $table.signatures,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get ntpReference => $composableBuilder(
    column: $table.ntpReference,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<double> get elapsedSeconds => $composableBuilder(
    column: $table.elapsedSeconds,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<double> get latitude => $composableBuilder(
    column: $table.latitude,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<double> get longitude => $composableBuilder(
    column: $table.longitude,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get capturedAt => $composableBuilder(
    column: $table.capturedAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get attempts => $composableBuilder(
    column: $table.attempts,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get nextAttempt => $composableBuilder(
    column: $table.nextAttempt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnFilters(column),
  );
}

class $$PhotoQueueTableOrderingComposer
    extends Composer<_$OfflineQueueDb, $PhotoQueueTable> {
  $$PhotoQueueTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<int> get id => $composableBuilder(
    column: $table.id,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get shiftId => $composableBuilder(
    column: $table.shiftId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get nonceValue => $composableBuilder(
    column: $table.nonceValue,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get filePaths => $composableBuilder(
    column: $table.filePaths,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get signatures => $composableBuilder(
    column: $table.signatures,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get ntpReference => $composableBuilder(
    column: $table.ntpReference,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<double> get elapsedSeconds => $composableBuilder(
    column: $table.elapsedSeconds,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<double> get latitude => $composableBuilder(
    column: $table.latitude,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<double> get longitude => $composableBuilder(
    column: $table.longitude,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get capturedAt => $composableBuilder(
    column: $table.capturedAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get attempts => $composableBuilder(
    column: $table.attempts,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get nextAttempt => $composableBuilder(
    column: $table.nextAttempt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get createdAt => $composableBuilder(
    column: $table.createdAt,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$PhotoQueueTableAnnotationComposer
    extends Composer<_$OfflineQueueDb, $PhotoQueueTable> {
  $$PhotoQueueTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<int> get id =>
      $composableBuilder(column: $table.id, builder: (column) => column);

  GeneratedColumn<String> get shiftId =>
      $composableBuilder(column: $table.shiftId, builder: (column) => column);

  GeneratedColumn<String> get nonceValue => $composableBuilder(
    column: $table.nonceValue,
    builder: (column) => column,
  );

  GeneratedColumn<String> get filePaths =>
      $composableBuilder(column: $table.filePaths, builder: (column) => column);

  GeneratedColumn<String> get signatures => $composableBuilder(
    column: $table.signatures,
    builder: (column) => column,
  );

  GeneratedColumn<String> get ntpReference => $composableBuilder(
    column: $table.ntpReference,
    builder: (column) => column,
  );

  GeneratedColumn<double> get elapsedSeconds => $composableBuilder(
    column: $table.elapsedSeconds,
    builder: (column) => column,
  );

  GeneratedColumn<double> get latitude =>
      $composableBuilder(column: $table.latitude, builder: (column) => column);

  GeneratedColumn<double> get longitude =>
      $composableBuilder(column: $table.longitude, builder: (column) => column);

  GeneratedColumn<String> get capturedAt => $composableBuilder(
    column: $table.capturedAt,
    builder: (column) => column,
  );

  GeneratedColumn<int> get attempts =>
      $composableBuilder(column: $table.attempts, builder: (column) => column);

  GeneratedColumn<int> get nextAttempt => $composableBuilder(
    column: $table.nextAttempt,
    builder: (column) => column,
  );

  GeneratedColumn<int> get createdAt =>
      $composableBuilder(column: $table.createdAt, builder: (column) => column);
}

class $$PhotoQueueTableTableManager
    extends
        RootTableManager<
          _$OfflineQueueDb,
          $PhotoQueueTable,
          PhotoQueueData,
          $$PhotoQueueTableFilterComposer,
          $$PhotoQueueTableOrderingComposer,
          $$PhotoQueueTableAnnotationComposer,
          $$PhotoQueueTableCreateCompanionBuilder,
          $$PhotoQueueTableUpdateCompanionBuilder,
          (
            PhotoQueueData,
            BaseReferences<_$OfflineQueueDb, $PhotoQueueTable, PhotoQueueData>,
          ),
          PhotoQueueData,
          PrefetchHooks Function()
        > {
  $$PhotoQueueTableTableManager(_$OfflineQueueDb db, $PhotoQueueTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$PhotoQueueTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$PhotoQueueTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$PhotoQueueTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                Value<String> shiftId = const Value.absent(),
                Value<String> nonceValue = const Value.absent(),
                Value<String> filePaths = const Value.absent(),
                Value<String> signatures = const Value.absent(),
                Value<String?> ntpReference = const Value.absent(),
                Value<double> elapsedSeconds = const Value.absent(),
                Value<double?> latitude = const Value.absent(),
                Value<double?> longitude = const Value.absent(),
                Value<String> capturedAt = const Value.absent(),
                Value<int> attempts = const Value.absent(),
                Value<int> nextAttempt = const Value.absent(),
                Value<int> createdAt = const Value.absent(),
              }) => PhotoQueueCompanion(
                id: id,
                shiftId: shiftId,
                nonceValue: nonceValue,
                filePaths: filePaths,
                signatures: signatures,
                ntpReference: ntpReference,
                elapsedSeconds: elapsedSeconds,
                latitude: latitude,
                longitude: longitude,
                capturedAt: capturedAt,
                attempts: attempts,
                nextAttempt: nextAttempt,
                createdAt: createdAt,
              ),
          createCompanionCallback:
              ({
                Value<int> id = const Value.absent(),
                required String shiftId,
                required String nonceValue,
                required String filePaths,
                required String signatures,
                Value<String?> ntpReference = const Value.absent(),
                required double elapsedSeconds,
                Value<double?> latitude = const Value.absent(),
                Value<double?> longitude = const Value.absent(),
                required String capturedAt,
                Value<int> attempts = const Value.absent(),
                Value<int> nextAttempt = const Value.absent(),
                required int createdAt,
              }) => PhotoQueueCompanion.insert(
                id: id,
                shiftId: shiftId,
                nonceValue: nonceValue,
                filePaths: filePaths,
                signatures: signatures,
                ntpReference: ntpReference,
                elapsedSeconds: elapsedSeconds,
                latitude: latitude,
                longitude: longitude,
                capturedAt: capturedAt,
                attempts: attempts,
                nextAttempt: nextAttempt,
                createdAt: createdAt,
              ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$PhotoQueueTableProcessedTableManager =
    ProcessedTableManager<
      _$OfflineQueueDb,
      $PhotoQueueTable,
      PhotoQueueData,
      $$PhotoQueueTableFilterComposer,
      $$PhotoQueueTableOrderingComposer,
      $$PhotoQueueTableAnnotationComposer,
      $$PhotoQueueTableCreateCompanionBuilder,
      $$PhotoQueueTableUpdateCompanionBuilder,
      (
        PhotoQueueData,
        BaseReferences<_$OfflineQueueDb, $PhotoQueueTable, PhotoQueueData>,
      ),
      PhotoQueueData,
      PrefetchHooks Function()
    >;
typedef $$NoncePoolTableCreateCompanionBuilder =
    NoncePoolCompanion Function({
      required String nonceValue,
      required String shiftId,
      required int expiresAt,
      Value<bool> drawn,
      Value<int> rowid,
    });
typedef $$NoncePoolTableUpdateCompanionBuilder =
    NoncePoolCompanion Function({
      Value<String> nonceValue,
      Value<String> shiftId,
      Value<int> expiresAt,
      Value<bool> drawn,
      Value<int> rowid,
    });

class $$NoncePoolTableFilterComposer
    extends Composer<_$OfflineQueueDb, $NoncePoolTable> {
  $$NoncePoolTableFilterComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnFilters<String> get nonceValue => $composableBuilder(
    column: $table.nonceValue,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<String> get shiftId => $composableBuilder(
    column: $table.shiftId,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<int> get expiresAt => $composableBuilder(
    column: $table.expiresAt,
    builder: (column) => ColumnFilters(column),
  );

  ColumnFilters<bool> get drawn => $composableBuilder(
    column: $table.drawn,
    builder: (column) => ColumnFilters(column),
  );
}

class $$NoncePoolTableOrderingComposer
    extends Composer<_$OfflineQueueDb, $NoncePoolTable> {
  $$NoncePoolTableOrderingComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  ColumnOrderings<String> get nonceValue => $composableBuilder(
    column: $table.nonceValue,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<String> get shiftId => $composableBuilder(
    column: $table.shiftId,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<int> get expiresAt => $composableBuilder(
    column: $table.expiresAt,
    builder: (column) => ColumnOrderings(column),
  );

  ColumnOrderings<bool> get drawn => $composableBuilder(
    column: $table.drawn,
    builder: (column) => ColumnOrderings(column),
  );
}

class $$NoncePoolTableAnnotationComposer
    extends Composer<_$OfflineQueueDb, $NoncePoolTable> {
  $$NoncePoolTableAnnotationComposer({
    required super.$db,
    required super.$table,
    super.joinBuilder,
    super.$addJoinBuilderToRootComposer,
    super.$removeJoinBuilderFromRootComposer,
  });
  GeneratedColumn<String> get nonceValue => $composableBuilder(
    column: $table.nonceValue,
    builder: (column) => column,
  );

  GeneratedColumn<String> get shiftId =>
      $composableBuilder(column: $table.shiftId, builder: (column) => column);

  GeneratedColumn<int> get expiresAt =>
      $composableBuilder(column: $table.expiresAt, builder: (column) => column);

  GeneratedColumn<bool> get drawn =>
      $composableBuilder(column: $table.drawn, builder: (column) => column);
}

class $$NoncePoolTableTableManager
    extends
        RootTableManager<
          _$OfflineQueueDb,
          $NoncePoolTable,
          NoncePoolData,
          $$NoncePoolTableFilterComposer,
          $$NoncePoolTableOrderingComposer,
          $$NoncePoolTableAnnotationComposer,
          $$NoncePoolTableCreateCompanionBuilder,
          $$NoncePoolTableUpdateCompanionBuilder,
          (
            NoncePoolData,
            BaseReferences<_$OfflineQueueDb, $NoncePoolTable, NoncePoolData>,
          ),
          NoncePoolData,
          PrefetchHooks Function()
        > {
  $$NoncePoolTableTableManager(_$OfflineQueueDb db, $NoncePoolTable table)
    : super(
        TableManagerState(
          db: db,
          table: table,
          createFilteringComposer: () =>
              $$NoncePoolTableFilterComposer($db: db, $table: table),
          createOrderingComposer: () =>
              $$NoncePoolTableOrderingComposer($db: db, $table: table),
          createComputedFieldComposer: () =>
              $$NoncePoolTableAnnotationComposer($db: db, $table: table),
          updateCompanionCallback:
              ({
                Value<String> nonceValue = const Value.absent(),
                Value<String> shiftId = const Value.absent(),
                Value<int> expiresAt = const Value.absent(),
                Value<bool> drawn = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => NoncePoolCompanion(
                nonceValue: nonceValue,
                shiftId: shiftId,
                expiresAt: expiresAt,
                drawn: drawn,
                rowid: rowid,
              ),
          createCompanionCallback:
              ({
                required String nonceValue,
                required String shiftId,
                required int expiresAt,
                Value<bool> drawn = const Value.absent(),
                Value<int> rowid = const Value.absent(),
              }) => NoncePoolCompanion.insert(
                nonceValue: nonceValue,
                shiftId: shiftId,
                expiresAt: expiresAt,
                drawn: drawn,
                rowid: rowid,
              ),
          withReferenceMapper: (p0) => p0
              .map((e) => (e.readTable(table), BaseReferences(db, table, e)))
              .toList(),
          prefetchHooksCallback: null,
        ),
      );
}

typedef $$NoncePoolTableProcessedTableManager =
    ProcessedTableManager<
      _$OfflineQueueDb,
      $NoncePoolTable,
      NoncePoolData,
      $$NoncePoolTableFilterComposer,
      $$NoncePoolTableOrderingComposer,
      $$NoncePoolTableAnnotationComposer,
      $$NoncePoolTableCreateCompanionBuilder,
      $$NoncePoolTableUpdateCompanionBuilder,
      (
        NoncePoolData,
        BaseReferences<_$OfflineQueueDb, $NoncePoolTable, NoncePoolData>,
      ),
      NoncePoolData,
      PrefetchHooks Function()
    >;

class $OfflineQueueDbManager {
  final _$OfflineQueueDb _db;
  $OfflineQueueDbManager(this._db);
  $$GpsQueueTableTableManager get gpsQueue =>
      $$GpsQueueTableTableManager(_db, _db.gpsQueue);
  $$WakefulnessQueueTableTableManager get wakefulnessQueue =>
      $$WakefulnessQueueTableTableManager(_db, _db.wakefulnessQueue);
  $$PhotoQueueTableTableManager get photoQueue =>
      $$PhotoQueueTableTableManager(_db, _db.photoQueue);
  $$NoncePoolTableTableManager get noncePool =>
      $$NoncePoolTableTableManager(_db, _db.noncePool);
}
